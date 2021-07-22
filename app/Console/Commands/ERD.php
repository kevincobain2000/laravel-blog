<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Schema;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use Exception;
use TypeError;
use Illuminate\Database\Eloquent\Relations\Relation;

class ERD extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gojs:erd';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate node data and link data for gojs ERD';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $namespace = 'App\Models\\';
        $modelsInPath = 'app/Models';

        $models = collect(File::allFiles(base_path($modelsInPath)))
                    ->map(function ($item) use ($namespace) {
                        $path = $item->getRelativePathName();
                        $class = sprintf(
                            '\%s%s',
                            $namespace,
                            strtr(substr($path, 0, strrpos($path, '.')), '/', '\\')
                        );
                        return $class;
                    })
                    ->filter(function ($class) {
                        $valid = false;

                        if (class_exists($class)) {
                            $reflection = new ReflectionClass($class);
                            $valid = $reflection->isSubclassOf(Model::class) && !$reflection->isAbstract();
                        }

                        return $valid;
                    });

        $linkDataArray = [];
        $nodeDataArray = [];

        foreach ($models as $model) {
            // get columns from model
            $nodeItems = [];
            $columns = Schema::getColumnListing(app($model)->getTable());

            foreach ($columns as $column) {
                $keyName = app($model)->getKeyName();
                if (is_array($keyName)) {
                    $isPrimary = in_array($column, $keyName);
                } else {
                    $isPrimary = $column == $keyName;
                }

                $nodeItems[] = [
                    "name"   => $column,
                    "isKey"  => $isPrimary,
                    "figure" => $isPrimary ? "Hexagon" : "Decision",
                    "color"  => $isPrimary ? "#be4b15" : "#6ea5f8" ,
                    "info"  => Schema::getColumnType(app($model)->getTable(), $column),
                ];
            }

            // var_dump($columns);
            $nodeDataArray[] = [
                "key" => app($model)->getTable(),
                "items" => $nodeItems
            ];

            try {
                $relationships = $this->getRelationships(app($model));
            } catch (\Throwable $th) {
                throw $th;
            }

            foreach ($relationships as $relationship) {
                $fromTable = app($model)->getTable();
                $toTable = app($relationship['model'])->getTable();
                // check if is array
                if (is_array($relationship['foreign_key']) || is_array($relationship['parent_key'])) {
                    $fromPort = "";
                    $toPort = "";
                } else {
                    $fromPort = $relationship['type'] == "BelongsTo" ? explode('.', $relationship["foreign_key"])[1] : explode('.', $relationship["parent_key"])[1];
                    $toPort = $relationship['type'] == "BelongsTo" ? explode('.', $relationship["parent_key"])[1] : explode('.', $relationship["foreign_key"])[1];
                }
                $linkDataArray[] = [
                    "from"   => $fromTable,
                    "to"     => $toTable,
                    "text"   => $this->getFromText($relationship),
                    "toText" => $this->getToText($relationship),
                    "fromPort"  =>  $fromPort,
                    "toPort"  => $toPort,
                    "type"   => $relationship['type'],
                ];
            }
        }
        // pretty print array to json
        $linkData = json_encode($linkDataArray, JSON_PRETTY_PRINT);
        $nodeData = json_encode($nodeDataArray, JSON_PRETTY_PRINT);
        // write json to file
        File::put(base_path('docs/erd/linkData.json'), $linkData);
        File::put(base_path('docs/erd/nodeData.json'), $nodeData);
        $this->info("data written successfully to ./docs/erd/");

        return 0;
    }

    public function getFromText($relationship)
    {
        $text = '';
        switch ($relationship['type']) {
            case 'BelongsTo':
                $text = "1..1\nBT";
                break;
            case 'BelongsToMany':
                $text = "1..N\nBTM";
                break;
            case 'HasMany':
                $text = "1..N\nHM";
                break;
            case 'HasOne':
                $text = "1..1\nHO";
                break;
            case 'MorphTo':
                $text = "1..1\nMT";
                break;
            case 'MorphMany':
                $text = "1..N\nMM";
                break;
        }
        return $text;
    }

    public function getToText($relationship)
    {
        $text = '';
        switch ($relationship['type']) {
            case 'BelongsTo':
                $text = '';
                break;
            case 'HasMany':
                $text = '';
                break;
            case 'HasOne':
                $text = '';
                break;
        }
        return $text;
    }

    public function getRelationships($model)
    {
        $relationships = [];
        $model = new $model;

        foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class != get_class($model) ||
                !empty($method->getParameters()) ||
                $method->getName() == __FUNCTION__) {
                continue;
            }

            try {
                $return = $method->invoke($model);
                if ($return instanceof Relation) {
                    $relationType = (new ReflectionClass($return))->getShortName();
                    $modelName = (new ReflectionClass($return->getRelated()))->getName();

                    $foreignKey = $return->getQualifiedForeignKeyName();
                    $parentKey = $return->getQualifiedParentKeyName();
                    $relationships[$method->getName()] = [
                        'type'        => $relationType,
                        'model'       => $modelName,
                        'foreign_key' => $foreignKey,
                        'parent_key'  => $parentKey,
                    ];
                }
            } catch (Throwable $e) {
                //ignore
            }
        }

        return $relationships;
    }
}
