<?php

namespace CodexShaper\DBM\Http\Controllers;

use CodexShaper\DBM\Database\Schema\Table;
use CodexShaper\DBM\Facades\Driver;
use CodexShaper\DBM\Models\CollectionField;
use CodexShaper\DBM\Models\DBM_Collection;
use DBM;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DatabaseController extends Controller
{

    public function index()
    {
        return view('dbm::app');
    }

    public function all(Request $request)
    {
        // return response()->json(['success' => false, 'data' => escapeshellarg($request->perPage)]);
        if ($request->ajax()) {

            if (($response = DBM::authorize('database.browse')) !== true) {
                return $response;
            }

            try
            {
                $perPage         = (int) $request->perPage;
                $query           = $request->q;
                $tables          = Table::paginate($perPage, null, [], $query);
                $userPermissions = DBM::userPermissions();

                $newTables = [];

                foreach ($tables as $table) {

                    $newTables[] = $table;
                }

                return response()->json([
                    'success'         => true,
                    'tables'          => $newTables,
                    'pagination'      => $tables,
                    'userPermissions' => $userPermissions,
                ]);

            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'errors'  => [$e->getMessage()],
                ], 400);
            }
        }
        return response()->json(['success' => false]);
    }

    public function getTable(Request $request)
    {
        if ($request->ajax()) {

            if (($response = DBM::authorize('database.update')) !== true) {
                return $response;
            }

            $userPermissions = DBM::userPermissions();
            $table           = Table::getTable($request->name);
            $object          = DBM::Object()->where('name', $request->name)->first();
            $isCrudExists    = false;
            $columns         = $table['columns'];
            $newColumns      = [];

            if ($object) {
                $fields = $object->fields()->orderBy('order', 'ASC')->get();
                if (count($fields) > 0) {
                    foreach ($fields as $field) {
                        foreach ($columns as $key => $column) {
                            if ($field->name == $column->name) {
                                // $column->id    = $field->id;
                                $column->order = $field->order;
                                $newColumns[]  = $column;
                                unset($columns[$key]);
                                $columns = array_values($columns);
                            }
                        }
                    }

                    $table['columns'] = $newColumns;
                    $columns          = $newColumns;
                    $isCrudExists     = true;
                }
            }

            return response()->json([
                'success'         => true,
                'table'           => $table,
                'isCrudExists'    => $isCrudExists,
                'userPermissions' => $userPermissions,
                'templates'       => DBM::templates(),
            ]);
        }

        return response()->json(['success' => false]);
    }

    public function getTableColumns(Request $request)
    {
        if ($request->ajax()) {

            if (($response = DBM::authorize('crud.update')) !== true) {
                return $response;
            }

            $table  = Table::getTable($request->table);
            $fields = $table['columns'];

            return response()->json(['success' => true, 'fields' => $fields]);
        }

        return response()->json(['success' => false]);
    }

    public function create(Request $request)
    {
        if ($request->ajax()) {

            $table = json_decode($request->table, true);

            if (($response = DBM::authorize('database.create')) !== true) {
                return $response;
            }

            try
            {
                Table::create($request->table);

                if (Driver::isMongoDB()) {
                    $collection           = new DBM_Collection;
                    $collection->name     = $table['name'];
                    $collection->old_name = $table['name'];
                    $collection->extra    = [];

                    if ($collection->save()) {
                        $id = $collection->_id;

                        $collection_field = new CollectionField;

                        $collection_field->dbm_collection_id = $id;
                        $collection_field->name              = '_id';
                        $collection_field->old_name          = '_id';
                        $collection_field->type              = 'ObjectId';
                        $collection_field->index             = 'PRIMARY_KEY';
                        $collection_field->extra             = [];

                        $collection_field->save();
                    }
                }
                return response()->json(['success' => true]);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'errors'  => [$e->getMessage()],
                ], 400);
            }
        }

        return response()->json(['success' => false]);
    }

    public function createObject($table)
    {
        if (($response = DBM::isLoggedIn()) !== true) {
            return $response;
        }

        if (($response = DBM::authorize('database.create')) !== true) {
            return $response;
        }

        if (!is_array($table)) {
            $table = json_decode($table, true);
        }

        $tableName = $table['name'];
        $columns   = $table['columns'];

        $object               = DBM::Object();
        $object->name         = $tableName;
        $object->slug         = Str::slug($tableName);
        $object->display_name = ucfirst($tableName);
        if ($object->save()) {
            foreach ($columns as $column) {
                $columnType = $column['type'];

                $field                = DBM::Field();
                $field->dbm_object_id = $object->id;
                $field->name          = $column['name'];
                $field->display_name  = strtoupper($column['name']);
                $field->type          = static::getInputType($columnType['name']);
                $field->required      = true;
                $field->create        = false;
                $field->read          = false;
                $field->edit          = false;
                $field->delete        = false;
                $field->order         = $column['order'];
                $field->save();
            }

            return true;
        }

        return false;

    }

    public function update(Request $request)
    {
        if ($request->ajax()) {

            if (($response = DBM::authorize('database.update')) !== true) {
                return $response;
            }

            if (!is_array($request->table)) {
                $table = json_decode($request->table, true);
            }

            $tableName = $table['oldName'];
            $newName   = $table['name'];
            $columns   = $table['columns'];

            try
            {
                // Update Template
                if (is_array($request->templates) && count($request->templates) > 0) {

                    foreach ($request->templates as $field) {

                        if ($template = DBM::Template()->where('old_name', $field['oldName'])->first()) {

                            $template->name           = $field['name'];
                            $template->old_name       = $field['name'];
                            $template->type           = $field['type']['name'];
                            $template->length         = $field['length'];
                            $template->index          = $field['index'];
                            $template->default        = $field['default'];
                            $template->notnull        = $field['notnull'];
                            $template->unsigned       = $field['unsigned'];
                            $template->auto_increment = $field['autoincrement'];

                            $template->update();
                        }
                    }
                }

                if (Table::exists($tableName)) {

                    if (Driver::isMongoDB()) {

                        if ($collection = DBM_Collection::where('name', $tableName)->first()) {
                            if ($tableName != $newName) {
                                $collection->name     = $newName;
                                $collection->old_name = $newName;
                                $collection->update();
                            }
                        } else {
                            $collection           = new DBM_Collection;
                            $collection->name     = $newName;
                            $collection->old_name = $newName;
                            $collection->save();
                        }

                        if ($collection) {

                            $id         = $collection->_id;
                            $fieldNames = (!empty($collection->fields)) ? $collection->fields->pluck('old_name')->toArray() : [];

                            foreach ($columns as $column) {

                                if (in_array($column['oldName'], $fieldNames)) {

                                    $collection_field = CollectionField::where('old_name', $column['oldName'])->first();

                                    $collection_field->name     = $column['name'];
                                    $collection_field->old_name = $column['oldName'];
                                    $collection_field->type     = $column['type']['name'];
                                    $collection_field->update();
                                    $fieldNames = array_values(array_diff($fieldNames, [$column['oldName']]));
                                } else {

                                    $collection_field = new CollectionField;

                                    $collection_field->dbm_collection_id = $id;
                                    $collection_field->name              = $column['name'];
                                    $collection_field->old_name          = $column['name'];
                                    $collection_field->type              = $column['type']['name'];
                                    $collection_field->index             = '';
                                    $collection_field->extra             = [];

                                    $collection_field->save();
                                }
                            }

                            if (count($fieldNames) > 0) {
                                foreach ($fieldNames as $fieldName) {
                                    $field = CollectionField::where([
                                        'dbm_collection_id' => $id,
                                        'name'              => $fieldName])->first();
                                    $field->delete();
                                }
                            }

                        }
                    }

                    // Update Database
                    Table::update($request->table);

                    // Update Crud fields
                    if ($tableName != $newName) {

                        DBM::Object()->where('slug', Str::slug($tableName))->update([
                            'name'         => $newName,
                            'slug'         => Str::slug($newName),
                            'display_name' => ucfirst($newName),
                        ]);

                        $tableName = $newName;
                    }

                    if ($object = DBM::Object()::where('slug', Str::slug($tableName))->first()) {

                        $fieldNames        = $object->fields->pluck('name')->toArray();
                        $relationshipItems = [];

                        foreach ($columns as $column) {

                            $columnType = $column['type'];

                            if (in_array($column['oldName'], $fieldNames)) {

                                $field = DBM::Field()->where([
                                    'dbm_object_id' => $object->id,
                                    'name'          => $column['oldName'],
                                ])->first();

                                $field->name = $column['name'];
                                if ($column['oldName'] != $column['name']) {
                                    $field->display_name = ucfirst($column['name']);
                                }
                                $field->order = $column['order'];
                                $field->update();

                                $fieldNames = array_values(array_diff($fieldNames, [$column['oldName']]));
                            } else {

                                if (DBM::Field()->where([
                                    'dbm_object_id' => $object->id,
                                    'name'          => $column['name']])->first()) {
                                    return response()->json([
                                        'success' => false,
                                        'errors'  => ["Field name must be unique. " . $column['name'] . " are duplicate"],
                                    ], 400);
                                }

                                $field                = DBM::Field();
                                $field->dbm_object_id = $object->id;
                                $field->name          = $column['name'];
                                $field->display_name  = ucfirst($column['name']);
                                $field->type          = static::getInputType($columnType['name']);
                                $field->order         = $column['order'];

                                if ($column['autoincrement'] == true) {
                                    $field->create = false;
                                    $field->read   = false;
                                    $field->edit   = false;
                                    $field->delete = false;
                                }

                                $field->save();
                            }

                        }

                        if (count($fieldNames) > 0) {
                            foreach ($fieldNames as $fieldName) {
                                $field = DBM::Field()->where([
                                    'dbm_object_id' => $object->id,
                                    'name'          => $fieldName])->first();
                                if ($field->type != 'relationship') {
                                    $field->delete();
                                }
                            }
                        }
                    }

                    return response()->json(['success' => true]);
                }

            } catch (\Exception $e) {

                return response()->json([
                    'success' => false,
                    'errors'  => [$e->getMessage()],
                ], 400);
            }
        }

        return response()->json(['success' => false]);

    }

    public function delete(Request $request)
    {
        if ($request->ajax()) {

            if (($response = DBM::authorize('database.delete')) !== true) {
                return $response;
            }

            $tableName = $request->table;

            try
            {
                if (Table::exists($tableName)) {

                    if (Driver::isMongoDB()) {
                        if ($collection = DBM_Collection::where('name', $tableName)->first()) {
                            $collection->fields()->delete();
                            $collection->delete();
                        }
                    }

                    if ($object = DBM::Object()->where('slug', Str::slug($tableName))->first()) {

                        $object->fields()->delete();
                        $object->delete();
                    }

                    Table::drop($tableName);

                    return response()->json(['success' => true]);
                }

            } catch (\Exception $e) {

                return response()->json([
                    'success' => false,
                    'errors'  => [$e->getMessage()],
                ], 400);
            }
        }

        return response()->json(['success' => false]);
    }

    public static function getInputType($name)
    {
        $types = static::inputTypes();
        foreach ($types as $type => $value) {
            if (in_array($name, $value)) {
                return $type;
            }
        }
        return 'text';
    }

    public static function inputTypes()
    {
        return [
            'number'    => [
                'tinyint',
                'smallint',
                'mediumint',
                'integer',
                'int',
                'bigint',
                'decimal',
                'numeric',
            ],
            'text'      => [
                'money',
                'float',
                'real',
                'double',
                'double precision',
                // String
                'char',
                'character',
                'varchar',
                'character varying',
                'string',
                'guid',
                'uuid',
                // Lists
                'json',
                'jsonb',
                'json_array',
            ],
            'password'  => [
                'password',
            ],
            'email'     => [],
            'select'    => [],
            'radio'     => [],
            'checkbox'  => [
                'boolean',
            ],
            'textarea'  => [
                'tinytext',
                'text',
                'mediumtext',
                'longtext',
                'tsquery',
                'tsvector',
                'xml',
            ],
            'date'      => [
                'date',
                'datetime',
                'year',
                'timetz',
                'timestamptz',
                'datetimetz',
                'dateinterval',
                'interval',
            ],
            'timestamp' => [
                'timestamp',
            ],
            'color'     => [],
            'file'      => [],
            'image'     => [],
            'hidden'    => [],
            'month'     => [],
            'week'      => [],
            'time'      => [
                'time',
            ],
            'url'       => [],
            'tel'       => [],
            'submit'    => [],
            'search'    => [],
        ];
    }

    /*
     * Store Template
     */
    public function saveTemplate(Request $request)
    {
        $field = $request->template;
        try
        {
            if (DBM::Template()->where('name', $field['name'])->first()) {
                return response()->json([
                    'success' => false,
                    'errors'  => [" The template name must be unique. " . $field['name'] . " already exist."],
                ], 400);
            }

            $template                 = DBM::Template();
            $template->name           = $field['name'];
            $template->old_name       = $field['name'];
            $template->type           = $field['type']['name'];
            $template->length         = $field['length'];
            $template->index          = $field['index'];
            $template->default        = $field['default'];
            $template->notnull        = $field['notnull'];
            $template->unsigned       = $field['unsigned'];
            $template->auto_increment = $field['autoincrement'];

            if ($template->save()) {
                return response()->json(['success' => true, 'templates' => DBM::templates()]);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'errors'  => [$e->getMessage()],
            ], 400);
        }
        return response()->json(['success' => true, 'template' => $request->all()]);
    }

    public function removeTemplate(Request $request)
    {
        if ($template = DBM::Template()->where('name', $request->name)->first()) {
            if ($template->delete()) {
                return response()->json(['success' => true, 'templates' => DBM::templates()]);
            }
        }
        return response()->json([
            'success' => false,
            'errors'  => ['The template '+$request->name . " not found"],
        ], 400);
    }

    public function getUniqueId()
    {
        $id = new \MongoDB\BSON\ObjectId();
        foreach ($id as $key => $value) {
            return response()->json(['success' => true, 'id' => $value]);
        }
    }

}
