<?php

namespace App\Api\Front;

use App\Common\Exceptions\ForbiddenException;
use App\Entities\FieldsChangesHistoryEntity;
use App\Helpers\ArrayHelper;
use App\Models\Claims\ClaimsFieldsData;
use App\Models\FieldsChangesHistoryModel;
use App\Models\FormConstructor\FieldModel;
use App\Models\LstofvalModel;
use CodeIgniter\Database\RawSql;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class FieldsChangesHistoryController extends BaseRestController
{
    protected $modelName = FieldsChangesHistoryModel::class;

    protected string $entity;
    protected int $entityId;

    /**
     * @var array Поля содержащие id офисов
     */
    protected array $officesIdsFields = ['guilty_do'];

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        $this->entity = (string)$this->request->getVar('entity');
        if (!isset(FieldsChangesHistoryModel::$entitiesClassMap[$this->entity])) {
            throw new ForbiddenException();
        }

        $this->entityId = (int)$this->request->getVar('entity_id');
        if (empty($this->entityId)) {
            return $this->respond();
        }
    }

    /**
     * @return mixed
     * @throws \ErrorException
     */
    public function index()
    {
        /* @var $builder FieldsChangesHistoryModel */
        $builder = $this->model;
        $tableName = $builder->getTableName();
        $builder->select(new RawSql("DISTINCT ON ({$tableName}.id) {$tableName}.*, auth_users.fio, constructor_fields.label AS constructor_field_label, constructor_fields.lst_code"))
            ->join('auth_users', "auth_users.user_id = {$tableName}.user_id", 'left')
            ->join('constructor_fields', "constructor_fields.name = {$tableName}.field", 'left')
            ->byEntity($this->entityId, $this->entity)
            ->orderBy($tableName . '.id', 'desc')
            ->asObject(FieldsChangesHistoryEntity::class);

        $filters = (array)$this->request->getVar('filter') ?: [];
        $filters = array_filter($filters);
        foreach ($filters as $filter => $value) {
            if (in_array($filter, ['user_id', 'field'])) {
                $builder->where("{$tableName}.{$filter}", $value);

            } elseif ($filter == 'created_at') {
                $builder->betweenDate(
                    $tableName . '.created',
                    $value['from'] . '  00:00:00',
                    $value['to'] . ' 23:59:59'
                );
            }
        }

        /** @var FieldsChangesHistoryEntity[] $results */
        $perPage = (int)$this->request->getVar('per_page') ?: 10;
        $results = $builder->paginate($perPage);

        $officesIds = $lstValues = [];
        foreach ($results as &$item) {
            if (in_array($item->field, $this->officesIdsFields)) {
                // собираем id офисов
                array_push($officesIds, (int)$item->value_before, (int)$item->value_after);
            }

            $lstCode = $item->lst_code;
            if (!empty($lstCode)) {
                // собираем значения справочников из конструкторских полей
                $lstValues[$lstCode][] = $item->value_before;
                $lstValues[$lstCode][] = $item->value_after;
            }

            $item = array_merge(
                $item->toArray(),
                [
                    'created' => $item->created->format('d.m.Y H:i:s'),
                    'fio' => mb_convert_case($item->fio, MB_CASE_TITLE),
                    'field_name' => $item->constructor_field_label ?: $item->getReadableFieldName(),
                    'value_before' => strip_tags(nl2br($item->value_before), ['p', 'br']),
                    'value_after' => strip_tags(nl2br($item->value_after), ['p', 'br']),
                ]
            );
            unset($item['constructor_field_label']);
        }

        $officesIds = array_unique(array_filter($officesIds));
        if (!empty($officesIds)) {
            // меняем id офисов на названия
            $db = db_connect();
            $offices = $db->table('offices o')
                ->select("o.num id, CONCAT(o.num, ' ', o.name) as name")
                ->whereIn(
                    'num',
                    array_map(
                        static function ($id) {
                            return (string)$id;
                        },
                        $officesIds
                    )
                )
                ->get()
                ->getResultArray();
            $offices = array_column($offices, 'name', 'id');
            foreach ($results as &$item) {
                if (in_array($item['field'], $this->officesIdsFields)) {
                    $item['value_before'] = ArrayHelper::get($offices, $item['value_before'], $item['value_before']);
                    $item['value_after'] = ArrayHelper::get($offices, $item['value_after'], $item['value_after']);
                }
            }
        }

        if (!empty($lstValues)) {
            // получаем человеко-читаемые названия значений справочников
            array_walk(
                $lstValues,
                static function (&$values, $lstCode) {
                    // если в строке находится массив достаем элементы и ставим в values
                    foreach ($values as $value) {
                        $decoded = !is_numeric($value) ? json_decode($value) : false;
                        // проверяем, является ли массивом со списком значений
                        if ($decoded && is_array($decoded) && array_is_list($decoded)) {
                            $values = array_merge($values, $decoded);
                        }
                    }

                    $values = array_unique(array_filter($values));
                    $values = "'" . implode("', '", $values) . "'";
                    $values = " OR (type = '{$lstCode}' AND name IN ({$values}))";
                }
            );
            $lstValues = ltrim(implode(' ', $lstValues), ' OR');
            $lstValues = (new LstofvalModel())
                ->select('type, name, val')
                ->where($lstValues)
                ->find();
            $lstValues = ArrayHelper::groupBy($lstValues, 'type');
            $lstValues = array_map(
                static function ($item) {
                    return array_column($item, 'val', 'name');
                },
                $lstValues
            );
        }

        return $this->respond(
            [
                'data' => $results,
                'meta' => $this->model->pager->getDetails(),
                'lstValues' => $lstValues,
            ]
        );
    }

    /**
     * Возвращает список значений для фильтраций по пользователю и полю.
     * Формат значений: [ [id: ..., name: ...], ]
     * @return mixed
     * @throws \ErrorException
     */
    public function filtersValues()
    {
        // уникальные значения user_id и field которые менялись по запрашиваемой сущности
        /* @var $builder FieldsChangesHistoryModel */
        $builder = $this->model;
        $builder->select(
            [
                "json_object_agg(distinct {$builder->getTableName()}.user_id, auth_users.fio) AS user_id",
                "json_object_agg(distinct {$builder->getTableName()}.field, {$builder->getTableName()}.entity) AS field"
            ]
        )
            ->join('auth_users', "auth_users.user_id = {$builder->getTableName()}.user_id", 'left')
            ->byEntity($this->entityId, $this->entity)
            ->asArray();
        $values = $builder->first();

        // $values['user_id'] приходит в виде json формата [ [user_id: fio], ]
        $users = (array)json_decode($values['user_id']);
        $values['user_id'] = [];
        foreach ($users as $id => $user) {
            $values['user_id'][] = [
                'id' => $id,
                'name' => $user,
            ];
        }

        // $values['field'] приходит в виде json формата [ [field: entity], ]
        $fields = (array)json_decode($values['field']);
        $values['field'] = [];
        if (!empty($fields)) {
            $constructorFieldsNames = (new FieldModel())->whereIn('name', array_keys($fields))->find();
            $constructorFieldsNames = $constructorFieldsNames ? array_column($constructorFieldsNames, 'label', 'name') : [];
            foreach ($fields as $field => $entity) {
                $entityClass = $entity;
                $name = ArrayHelper::get($entityClass::$fieldsNames, $field);
                if (empty($name)) {
                    $name = ArrayHelper::get($constructorFieldsNames, $field);
                }
                $values['field'][] = [
                    'id' => $field,
                    'name' => $name ?: $field,
                ];
            }
        }

        // сортировка значений по алфавиту
        usort($values['user_id'], [$this, 'sortValues']);
        usort($values['field'], [$this, 'sortValues']);

        return $this->respond(
            [
                'values' => $values,
            ]
        );
    }

    /**
     * @param array $a
     * @param array $b
     * @return int
     */
    protected function sortValues(array $a, array $b): int
    {
        return strcmp($a['name'], $b['name']);
    }

    /**
     * Добавить запись
     * @return \CodeIgniter\HTTP\Response
     * @throws \ReflectionException
     */
    public function addFieldsHistory(){
        $insertData = [
            'field' => $this->request->getVar('field' ),
            'entity_id' => $this->request->getVar('entity_id' ),
            'value_before' => $this->request->getVar('value_before' ) ?? null,
            'value_after' => $this->request->getVar('value_after' ) ?? null,
        ];
        $result = (new FieldsChangesHistoryModel())->add($insertData);

        return $this->respond([ 'result' => $result ]);
    }
}
