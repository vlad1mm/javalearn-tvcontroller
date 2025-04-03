<?php

namespace App\Entities;

use App\Helpers\ArrayHelper;

/**
 * Class FieldsChangesHistoryEntity
 * @package App\Entities
 *
 * @property int $user_id id пользователя сделавшего изменения
 * @property int $entity_id id изменяемой сущности
 * @property string $entity изменяемая сущность
 * @property string $field код изменяемого поля
 * @property string $value_before предыдущее значенние
 * @property string $value_after новой значение
 */
class FieldsChangesHistoryEntity extends Entity
{
    protected $casts = [
        'id' => 'int',
        'user_id' => 'int',
        'entity_id' => 'int',
        'created' => 'datetime',
    ];

    /**
     * Возвращает человекочитаемое название поля по его коду из массива $fieldsNames измененной модели
     * @return string
     */
    public function getReadableFieldName(): string
    {
        $class = $this->entity;
        $field = $this->field;

        return ArrayHelper::get($class::$fieldsNames, $field, $field);
    }
}
