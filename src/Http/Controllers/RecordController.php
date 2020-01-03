<?php

namespace CodexShaper\DBM\Http\Controllers;

use CodexShaper\DBM\Database\Drivers\MongoDB\Type;
use CodexShaper\DBM\Database\Schema\Table;
use CodexShaper\DBM\Facades\Driver;
use CodexShaper\DBM\Traits\RecordHelper;
use DBM;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RecordController extends Controller
{
    use RecordHelper;

    public function index()
    {
        return view('dbm::app');
    }

    public function store(Request $request)
    {
        if ($request->ajax()) {

            if (($response = DBM::authorize('record.create')) !== true) {
                return $response;
            }

            $tableName       = $request->table;
            $originalColumns = Table::getColumnsName($tableName);
            $columns         = json_decode($request->columns);
            $fields          = json_decode($request->fields);

            $errors = $this->validation($fields, $columns);

            if (count($errors) > 0) {
                return $this->generateError($errors);
            }

            $object = DBM::Object()->where('name', $tableName)->first();
            $model  = $object->model;

            if (!class_exists($model)) {
                return $this->generateError(["Model not found. Please create model first"]);
            }

            try {

                $table = DBM::model($model, $tableName);

                foreach ($columns as $column => $value) {
                    if (in_array($column, $originalColumns)) {

                        if ($request->hasFile($column)) {
                            $value = $this->saveFiles($request, $column, $tableName);
                        }

                        if (!Driver::isMongoDB()) {
                            if ($functionName = $this->hasFunction($fields, $column)) {
                                $value = $this->executeFunction($functionName, $value);
                            }
                        }

                        $table->{$column} = $this->prepareStoreField($value, $tableName, $column);
                    }
                }

                if ($table->save()) {
                    $this->storeRelationshipData($fields, $columns, $object, $table);
                    return response()->json(['success' => true, 'object' => $object, 'table' => $table]);
                }

            } catch (\Exception $e) {
                return $this->generateError([$e->getMessage()]);
            }
        }

        return response()->json(['success' => false]);
    }

    public function saveFiles($request, $column, $tableName)
    {
        $files  = $request->file($column);
        $values = [];
        foreach ($files as $file) {
            $fileName = Str::random(config('dbm.filesystem.random_length')) . '.' . $file->getClientOriginalExtension();
            $path     = 'public/dbm/' . $tableName;
            $file->storeAs($path, $fileName);
            $values[] = $fileName;
        }

        if (count($values) > 1) {
            $value = $values;
            if (!Driver::isMongoDB()) {
                $value = json_encode($values);
            }
        }

        if (count($values) == 1) {
            $value = $values[0];
        }

        return $value;
    }

    public function prepareStoreField($value, $tableName, $column)
    {
        $value = is_array($value) ? json_encode($value) : $value;

        if (Driver::isMongoDB()) {

            $fieldType = $this->getFieldType($tableName, $column);

            if (!in_array($fieldType, Type::getTypes())) {
                $this->generateError([$fieldType . " type not supported."]);
            }

            $value = Type::$fieldType($value);

        }

        return $value;
    }

    public function storeRelationshipData($fields, $columns, $object, $table)
    {
        foreach ($fields as $field) {

            if (isset($field->relationship) && $field->relationship->relationType == "belongsToMany") {

                $relationship = $field->relationship;

                $localModel      = $relationship->localModel;
                $localTable      = $relationship->localTable;
                $foreignModel    = $relationship->foreignModel;
                $pivotTable      = $relationship->pivotTable;
                $parentPivotKey  = $relationship->parentPivotKey;
                $relatedPivotKey = $relationship->relatedPivotKey;

                $findColumn = $object->details['findColumn'];

                $localObject = DBM::model($localModel, $localTable)::where($findColumn, $table->{$findColumn})->first();

                DBM::Object()
                    ->setManyToManyRelation(
                        $localObject,
                        $foreignModel,
                        $pivotTable,
                        $parentPivotKey,
                        $relatedPivotKey
                    )
                    ->belongs_to_many()
                    ->attach($columns->{$relatedPivotKey});
            }
        }
    }

    public function update(Request $request)
    {
        if ($request->ajax()) {

            if (($response = DBM::authorize('record.update')) !== true) {
                return $response;
            }

            $tableName       = $request->table;
            $originalColumns = Table::getColumnsName($tableName);
            $columns         = json_decode($request->columns);
            $fields          = json_decode($request->fields);

            $errors = $this->validation($fields, $columns, 'update');

            if (count($errors) > 0) {
                return $this->generateError($errors);
            }

            $object  = DBM::Object()->where('name', $tableName)->first();
            $model   = $object->model;
            $details = $object->details;
            $key     = $details['findColumn'];

            if (!class_exists($model)) {
                return $this->generateError(["Model not found. Please create model first"]);
            }

            try {

                $table = DBM::model($model, $tableName)->where($key, $columns->{$key})->first();

                foreach ($columns as $column => $value) {

                    if (in_array($column, $originalColumns)) {

                        if ($request->hasFile($column)) {
                            $value = $this->saveFiles($request, $column, $tableName);
                        }

                        if ($value !== null && $value !== "") {

                            if (!Driver::isMongoDB()) {
                                if ($functionName = $this->hasFunction($fields, $column)) {
                                    $value = $this->executeFunction($functionName, $value);
                                }
                            }

                            $table->{$column} = $this->prepareStoreField($value, $tableName, $column);
                        }
                    }
                }

                if ($table->update()) {
                    $this->updateRelationshipData($fields, $columns, $object, $table);
                    return response()->json(['success' => true]);
                }

            } catch (\Exception $e) {
                return $this->generateError([$e->getMessage()]);
            }
        }

        return response()->json(['success' => false]);
    }

    public function updateRelationshipData($fields, $columns, $object, $table)
    {
        foreach ($fields as $field) {

            if (isset($field->relationship)) {

                $relationship = $field->relationship;

                $localModel   = $relationship->localModel;
                $localTable   = $relationship->localTable;
                $foreignModel = $relationship->foreignModel;

                if ($field->relationship->relationType == "belongsToMany") {
                    $pivotTable      = $relationship->pivotTable;
                    $parentPivotKey  = $relationship->parentPivotKey;
                    $relatedPivotKey = $relationship->relatedPivotKey;

                    $findColumn = $object->details['findColumn'];

                    $localObject = DBM::model($localModel, $localTable)->where($findColumn, $table->{$findColumn})->first();

                    DBM::Object()
                        ->setManyToManyRelation(
                            $localObject,
                            $foreignModel,
                            $pivotTable,
                            $parentPivotKey,
                            $relatedPivotKey
                        )
                        ->belongs_to_many()
                        ->sync($columns->{$relatedPivotKey});
                }

            }
        }
    }

    public function delete(Request $request)
    {
        if ($request->ajax()) {

            if (($response = DBM::authorize('record.delete')) !== true) {
                return $response;
            }

            $tableName = $request->table;
            // $originalColumns = Table::getColumnsName($tableName);
            $columns = json_decode($request->columns);
            $fields  = $request->fields;
            $object  = DBM::Object()->where('name', $tableName)->first();
            $model   = $object->model;
            $details = $object->details;
            $key     = $details['findColumn'];

            if (!class_exists($model)) {
                return $this->generateError(["Model not found. Please create model first"]);
            }

            try {
                $table = DBM::model($model, $tableName)->where($key, $columns->{$key})->first();
                if ($table) {
                    // Remove Relationship data
                    foreach ($fields as $field) {
                        $field = json_decode($field);
                        $this->removeRelationshipData($field, $object, $table);
                    }
                    // Check Table deleted successfully
                    if ($table->delete()) {
                        return response()->json(['success' => true]);
                    }
                }

            } catch (\Exception $e) {
                return $this->generateError([$e->getMessage()]);
            }
        }

        return response()->json(['success' => false]);
    }

    public function getTableDetails(Request $request)
    {
        if ($request->ajax()) {

            if (($response = DBM::authorize('record.browse')) !== true) {
                return $response;
            }

            $tableName = $request->table;
            $object    = DBM::Object()->where('name', $tableName)->first();

            if (!$object) {
                return response()->json([
                    'success' => false,
                    'errors'  => ["There is no Object details"],
                ], 400);
            }

            $createFields     = $object->createFields();
            $browseFields     = $object->readFields();
            $editFields       = $object->editFields();
            $deleteFields     = $object->deleteFields();
            $fields           = $object->allFields();
            $foreignTableData = [];

            $createFields = $this->prepareRecordFields($createFields);
            $editFields   = $this->prepareRecordFields($editFields);

            $model = $object->model;

            if (!class_exists($model)) {
                return $this->generateError(["Model not found. Please create model first"]);
            }

            $perPage      = (int) $request->perPage;
            $query        = $request->q;
            $searchColumn = $object->details['searchColumn'];
            $records      = DBM::model($model, $tableName)->paginate($perPage);

            if (!empty($query) && !empty($searchColumn)) {
                $records = DBM::model($model, $tableName)
                    ->where($searchColumn, 'LIKE', '%' . $query . '%')
                    ->paginate($perPage);
            }

            $records       = $this->prepareRelationshipData($records, $browseFields, $object);
            $recordsDetail = $this->prepareJsonFieldData($records, $fields, $object, $request->findValue);

            $objectDetails            = $object->details;
            $objectDetails['perPage'] = $perPage;

            return response()->json([
                'success'          => true,
                'object'           => $object,
                'objectDetails'    => $objectDetails,
                'createFields'     => $createFields,
                'browseFields'     => $browseFields,
                'editFields'       => $editFields,
                'deleteFields'     => $deleteFields,
                'records'          => $recordsDetail['records'],
                'record'           => $recordsDetail['record'],
                'foreignTableData' => $foreignTableData,
                'userPermissions'  => DBM::userPermissions(),
                'pagination'       => $records,
            ]);
        }

        return response()->json(['success' => false]);
    }

    public function prepareRecordFields($fields)
    {
        foreach ($fields as $key => $field) {

            if ($field->type == 'relationship') {

                $relationship            = $field->settings;
                $foreignModel            = $relationship['foreignModel'];
                $foreignKey              = $relationship['foreignKey'];
                $fields                  = $this->removeRelationshipKeyForBelongsTo($fields, $foreignKey);
                $field->foreignTableData = $foreignModel::all();
                $field->relationship     = $relationship;
                continue;
            }

            if (isset($field->settings['options'])) {
                $options = $this->getSettingOptions($field);
                if (is_array($options)) {
                    $fields[$key]->options = $options;
                }
            }
        }

        return $fields;
    }

    public function prepareJsonFieldData($records, $fields, $object, $findValue)
    {
        $newRecords = [];
        $newRecord  = new \stdClass();

        foreach ($records as $item => $record) {

            foreach ($fields as $key => &$field) {

                if (isset($record->{$field->name})) {

                    $record->{$field->name} = is_json($record->{$field->name}) ? json_decode($record->{$field->name}, true) : $record->{$field->name};
                }
            }

            if ($findValue && $record->{$object->details['findColumn']} == $findValue) {
                $newRecord = $record;
            }

            $newRecords[] = $record;
        }

        return [
            "records" => $newRecords,
            "record"  => $newRecord,
        ];
    }

    public function prepareRelationshipData($records, $browseFields, $object)
    {
        foreach ($records as $item => $record) {

            foreach ($browseFields as $field) {

                if ($field->type == 'relationship') {

                    $relationship = $field->settings;

                    $findColumn = $object->details['findColumn'];

                    $localModel       = $relationship['localModel'];
                    $localKey         = $relationship['localKey'];
                    $foreignModel     = $relationship['foreignModel'];
                    $foreignKey       = $relationship['foreignKey'];
                    $relationshipType = $relationship['relationType'];
                    $displayLabel     = $relationship['displayLabel'];

                    if ($relationshipType == 'belongsTo') {

                        $localObject = $localModel::where($findColumn, $record->{$findColumn})->first();

                        $datas = DBM::Object()->setCommonRelation($localObject, $foreignModel, $foreignKey, $localKey)->belongs_to;

                        $record->{$field->name}  = $datas;
                        $field->displayLabel     = $displayLabel;
                        $field->localKey         = $localKey;
                        $field->foreignKey       = $foreignKey;
                        $field->relationshipType = $relationshipType;

                    } else if ($relationshipType == 'hasMany') {

                        $localObject = $localModel::where($findColumn, $record->{$findColumn})->first();
                        $datas       = DBM::Object()->setCommonRelation($localObject, $foreignModel, $foreignKey, $localKey)->has_many;

                        $record->{$field->name}  = $datas;
                        $field->displayLabel     = $displayLabel;
                        $field->localKey         = $localKey;
                        $field->foreignKey       = $foreignKey;
                        $field->relationshipType = $relationshipType;

                    } else if ($relationshipType == 'belongsToMany') {

                        $pivotTable      = $relationship['pivotTable'];
                        $parentPivotKey  = $relationship['parentPivotKey'];
                        $relatedPivotKey = $relationship['relatedPivotKey'];

                        $localObject = $localModel::where($findColumn, $record->{$findColumn})->first();

                        $datas = DBM::Object()->setManyToManyRelation($localObject, $foreignModel, $pivotTable, $parentPivotKey, $relatedPivotKey)->belongs_to_many;

                        $record->{$field->name}  = $datas;
                        $field->displayLabel     = $displayLabel;
                        $field->localKey         = $localKey;
                        $field->foreignKey       = $foreignKey;
                        $field->relationshipType = $relationshipType;
                    }
                }
            }
        }

        return $records;
    }
}
