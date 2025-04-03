<?php

namespace App\Common\Traits\Models;

use App\Enum\FormConstructor\FieldEnum;
use App\Enum\UsersEnum;
use App\Helpers\Arr;
use App\Helpers\ArrayHelper;
use App\Models\Claims\ClaimsFieldsData;
use App\Models\FieldsChangesHistoryModel;
use App\Models\FormConstructor\FieldModel;

/**
 * Trait FieldsChangesHistoryTrait
 * Сохранение истории изменения полей модели.
 * Для использования у модели необходимо указать свойства: changesHistoryFields, fieldsNames, при необходимости changesHistoryRelationKey
 * @property array $changesHistoryFields Массив с кодами полей, изменения которых нужно сохранять в истории
 * @property array $fieldsNames Массив c названием полей
 * @property string $changesHistoryRelationKey Поле изменяемой модели из которого брать id для связки с [changesHistoryModel]
 * @package App\Common\Traits\Models
 */
trait FieldsChangesHistoryTrait
{
    /**
     * Проверка обязательных свойств и добавление события beforeUpdate
     * @throws \Exception
     */
    protected function initializeFieldsChangesHistory()
    {
        $this->checkRequiredFields(['changesHistoryFields', 'fieldsNames']);
        $this->addEventCallback('beforeUpdate', 'saveFieldsChangesHistory');
    }

    /**
     * Обработчик события beforeUpdate для сохранения изменений значений полей модели.
     * Должен вернуть исходный массив полей из параметра $data
     * @param array $data массив с ключами: id - id обновляемой модели, может быть несколько;
     *                    data - список изменившихся полей с новыми значениями
     * @return array
     * @throws \ReflectionException
     */
    protected function saveFieldsChangesHistory(array $data): array
    {
        // проверяем что задан список полей, изменения которых надо сохранять
        if (empty($this->changesHistoryFields)) {
            return $data;
        }

        $insertData = [];
        $isConstructorField = $this instanceof ClaimsFieldsData;
        $fieldsWithLogChangesFlag = (array)(new FieldModel())->where('log_changes', true)->findColumn('name');

        // текущие еще не сохраненные объекты обновляемых моделей со старыми значениями, для сравнения с новыми значениями
        $objects = $this->whereIn($this->primaryKey, $data['id'])->asArray()->find();
        $objects = array_column($objects, null, $this->primaryKey);

        foreach ($data['id'] as $id) {
            $object = Arr::get($objects, $id);
            if (empty($object)) {
                continue;
            }
            redefine($object); // для приведения boolean типов - вместо casts, т.к. здесь мы не знаем какой Entity используется с моделью

            // проходим по всем полям, которые были изменены
            foreach (array_keys($data['data']) as $field) {
                $fieldName = $isConstructorField ? $object['name'] : $field;
                if (!in_array($fieldName, $this->changesHistoryFields) && !in_array($fieldName, $fieldsWithLogChangesFlag)) {
                    // логируем только изменения полей которые перечислены в свойстве changesHistoryFields текущей модели,
                    // или это поле конструктора с флагом Логировать изменение значений
                    continue;
                }

                $entity = static::class;
                $entityId = $object[$this->changesHistoryRelationKey ?: $this->primaryKey];
                $valueBefore = trim(ArrayHelper::get($object, $field));
                $valueAfter = trim(ArrayHelper::get($data['data'], $field));

                // если у модели есть приведение типов или это поле конструктора,
                // то значения чекбоксов формы приводим в свой формат, чтобы не путать с 0/1
                if (
                    (property_exists($this, 'casts') && ArrayHelper::get((array)$this->casts, $field) == 'bool')
                    || ($isConstructorField && $object['data_type'] == FieldEnum::DATA_TYPE_CHECKBOX)
                ) {
                    // значение может быть 0/1 или true/false
                    $valueBefore = (int)$valueBefore || $valueBefore == 'true' ? 'Y' : 'N';
                    $valueAfter = (int)$valueAfter || $valueAfter == 'true' ? 'Y' : 'N';
                }

                $valueBefore = in_array($valueBefore, ['undefined', 'null']) ? '' : $valueBefore;
                $valueAfter = in_array($valueAfter, ['undefined', 'null']) ? '' : $valueAfter;

                if ($valueBefore == $valueAfter) {
                    continue;
                }

                $insertData[] = [
                    'field' => $fieldName,
                    'entity' => $entity,
                    'entity_id' => $entityId,
                    'user_id' => user()->getId() ?? UsersEnum::ADMIN_ID,
                    'value_before' => $valueBefore,
                    'value_after' => $valueAfter,
                ];
            }
        }

        if (!empty($insertData)) {
            (new FieldsChangesHistoryModel())->insertBatch($insertData);
        }

        return $data;
    }
}