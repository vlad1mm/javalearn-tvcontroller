<?php

namespace App\Models;

use App\Entities\FieldsChangesHistoryEntity;
use App\Enum\UsersEnum;
use App\Helpers\Arr;
use App\Helpers\ArrayHelper;
use App\Models\BaseModel as Model;
use App\Models\Claims\ClaimsFieldsData;
use App\Models\Claims\ClaimsQualityControlModel;
use App\Models\Claims\ClaimsResultsModel;

/**
 * Class FieldsChangesHistoryModel
 * @package App\Models
 */
class FieldsChangesHistoryModel extends Model
{
    protected $table = 'fields_changes_history';

    protected $returnType = FieldsChangesHistoryEntity::class;

    protected $useTimestamps = true;

    protected $updatedField = '';

    protected $allowedFields = [
        'field',
        'entity',
        'entity_id',
        'user_id',
        'value_before',
        'value_after',
    ];

    public static array $fieldsNames = [
        'value' => 'Значение',
    ];

    public const ENTITY_CLAIM = 'claim';

    /**
     * @var array Маппинг сущности и её моделей.
     * Необходим для поиска записей по сущности, у которой поля могут храниться в разных моделях
     * Формат: [имя_сущности: [список классов моделей,],] или [имя_сущности: класс модели,]
     */
    public static array $entitiesClassMap = [
        self::ENTITY_CLAIM => [
            ClaimsModel::class,
            ClaimsResultsModel::class,
            ClaimsQualityControlModel::class,
            ClaimsFieldsData::class,
            self::class
        ]
    ];

    /**
     * Поиск по коду сущности и её id
     * @param int|string $id
     * @param string $entity
     * @return $this
     * @throws \ErrorException
     */
    public function byEntity($id, string $entity): self
    {
        $classMap = ArrayHelper::get(self::$entitiesClassMap, $entity);
        if (empty($classMap)) {
            throw new \ErrorException();
        }
        if (is_array($classMap)) {
            $this->whereIn('entity', $classMap);
        } else {
            $this->where('entity', $classMap);
        }
        $this->where('entity_id', (string)$id);

        return $this;
    }

    /**
     * @param array $data
     * @return \CodeIgniter\Database\BaseResult|false|int|object|string
     * @throws \ErrorException
     * @throws \ReflectionException
     */
    public function add(array $data){
        $entity = Arr::get($data, 'entity');
        if (!Arr::get(self::$entitiesClassMap, $entity))  throw new \Exception('Не найдено значение сущности модели '. $entity);

        $data['user_id'] = user()->getId() ?? UsersEnum::ADMIN_ID;
        $data['entity'] = static::class;

        return $this->insert($data);
    }
}
