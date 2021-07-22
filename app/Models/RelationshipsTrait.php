<?php

namespace App\Models;

use Throwable;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;

trait RelationshipsTrait
{
    public function relationships()
    {
        $model = new static;

        $relationships = [];

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
