<?php

namespace Neurony\Duplicate\Traits;

use Closure;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Neurony\Duplicate\Helpers\RelationHelper;
use Neurony\Duplicate\Options\DuplicateOptions;

trait HasDuplicates
{
    /**
     * The container for all the options necessary for this trait.
     * Options can be viewed in the Neurony\Duplicate\Options\DuplicateOptions file.
     *
     * @var DuplicateOptions
     */
    protected $duplicateOptions;

    /**
     * Set the options for the HasDuplicates trait.
     *
     * @return DuplicateOptions
     */
    abstract public function getDuplicateOptions(): DuplicateOptions;

    /**
     * Register a duplicating model event with the dispatcher.
     *
     * @param Closure|string  $callback
     * @return void
     */
    public static function duplicating($callback): void
    {
        static::registerModelEvent('duplicating', $callback);
    }

    /**
     * Register a duplicated model event with the dispatcher.
     *
     * @param Closure|string  $callback
     * @return void
     */
    public static function duplicated($callback): void
    {
        static::registerModelEvent('duplicated', $callback);
    }

    /**
     * Return false if you want to skip the duplication of the model.
     *
     * @return bool
     */
    public function shouldDuplicate(): bool
    {
        return true;
    }

    /**
     * The container for all the options necessary for this trait.
     * Options can be viewed in the Neurony\Duplicate\Options\DuplicateOptions file.
     *
     * @var string $cacheKey
     */
    public $cacheKey = null;

    /**
     * Get the cache key
     */
    public function getCacheKey()
    {
        if ($this->cacheKey) {
            return $this->cacheKey;
        }

        return Str::snake(class_basename($this) . '_' . $this->id);
    }

    /**
     * @param Model $duplicate
     * @return Model
     */
    public function afterDuplication(Model $duplicate)
    {
        return $duplicate;
    }

    /**
     * Function to run after the duplicated instance is saved
     *
     * @param Model $duplicate
     * @return Model
     */
    public function afterDuplicationSave(Model $duplicate)
    {
        return $duplicate;
    }

    /**
     * Duplicate a model instance and it's relations.
     *
     * @return Model|bool
     * @throws Exception
     */
    public function saveAsDuplicate(string $cacheKey = null)
    {
        if ($cacheKey) {
            $this->cacheKey = $cacheKey;
        }

        if ($this->fireModelEvent('duplicating') === false) {
            return false;
        }

        $this->initDuplicateOptions();

        $model = DB::transaction(function () {
            $model = $this->duplicateModel();

            if ($this->duplicateOptions->shouldDuplicateDeeply !== true) {
                return $model;
            }

            foreach ($this->getRelationsForDuplication() as $relation => $attributes) {
                if (RelationHelper::isChild($attributes['type'])) {
                    $this->duplicateDirectRelation($model, $relation);
                }

                if (RelationHelper::isPivoted($attributes['type'])) {
                    $this->duplicatePivotedRelation($model, $relation);
                }
            }

            if (method_exists($this, 'afterDuplication')) {
                $model = $this->afterDuplication($model);
            }

            return $model;
        });

        $this->fireModelEvent('duplicated', false);

        return $model;
    }

    /**
     * Duplicate a model instance and it's relations.
     *
     * @return Model|bool
     * @throws Exception
     */
    public function saveDuplicateRelations(Model $original, Model $model)
    {
        if ($original->fireModelEvent('duplicating') === false) {
            return false;
        }

        $original->initDuplicateOptions();

        $model = DB::transaction(function () use (&$original, &$model) {
            if ($original->duplicateOptions->shouldDuplicateDeeply !== true) {
                return $model;
            }

            foreach ($original->getRelationsForDuplication() as $relation => $attributes) {
                if (RelationHelper::isChild($attributes['type'])) {
                    $original->duplicateDirectRelation($model, $relation);
                }

                if (RelationHelper::isPivoted($attributes['type'])) {
                    $original->duplicatePivotedRelation($model, $relation);
                }
            }

            return $model;
        });

        $original->fireModelEvent('duplicated', false);

        return $model;
    }

    /**
     * Get a replicated instance of the original model's instance.
     *
     * @return Model
     * @throws Exception
     */
    protected function duplicateModel(): Model
    {
        $model = $this->duplicateModelWithExcluding();
        $model = $this->duplicateModelWithUnique($model);
        $model->save();
        $model->cacheKey = $this->getCacheKey();

        $this->addDuplicatedModelToCache($this, $model);

        if (method_exists($this, 'afterDuplicationSave')) {
            $model = $this->afterDuplicationSave($model);
        }

        return $model;
    }

    /**
     * Duplicate a direct relation.
     * Subsequently save new relation records for the initial model instance.
     *
     * @param Model $model
     * @param string $relation
     * @return Model
     * @throws Exception
     */
    protected function duplicateDirectRelation(Model $model, string $relation): Model
    {
        $this->{$relation}()->get()->each(function ($rel) use ($model, $relation) {
            $original = $rel;

            if (method_exists($original, 'shouldDuplicate') && !$original->shouldDuplicate()) {
                return;
            }

            $rel = $this->duplicateRelationWithExcluding($rel, $relation);
            $rel = $this->duplicateRelationWithUnique($rel, $relation);
            $rel = $model->{$relation}()->save($rel);
            $rel->cacheKey = $model->getCacheKey();
            $original->cacheKey = $model->getCacheKey();

            $this->addDuplicatedModelToCache($original, $rel);

            if (method_exists($original, 'afterDuplicationSave')) {
                $rel = $original->afterDuplicationSave($rel);
            }

            if (method_exists($original, 'saveAsDuplicate')) {
                $original->saveDuplicateRelations($original, $rel);
            }

            if (method_exists($original, 'afterDuplication')) {
                $rel = $original->afterDuplication($rel);
            }
        });

        return $model;
    }

    /**
     * Duplicate a pivoted relation.
     * Subsequently attach new pivot records corresponding to the relation for the initial model instance.
     *
     * @param Model $model
     * @param string $relation
     * @return Model
     * @throws Exception
     */
    protected function duplicatePivotedRelation(Model $model, string $relation): Model
    {
        $this->{$relation}()->get()->each(function ($rel) use ($model, $relation) {
            $original = $rel;

            if (method_exists($original, 'shouldDuplicate') && !$original->shouldDuplicate()) {
                return;
            }

            $attributes = $this->establishDuplicatablePivotAttributes($rel);

            $model->{$relation}()->attach($rel, $attributes);
            $rel->cacheKey = $this->getCacheKey();

            if (method_exists($original, 'afterDuplicationSave')) {
                $rel = $original->afterDuplicationSave($rel);
            }

            if (method_exists($original, 'saveAsDuplicate')) {
                $original->saveDuplicateRelations($original, $rel);
            }
        });

        return $model;
    }

    /**
     * Get the relations that should be duplicated alongside the original model.
     *
     * @return array
     * @throws \ReflectionException
     */
    protected function getRelationsForDuplication(): array
    {
        $relations = [];
        $excluded = $this->duplicateOptions->excludedRelations ?: [];

        foreach (RelationHelper::getModelRelations($this) as $relation => $attributes) {
            if (! in_array($relation, $excluded)) {
                $relations[$relation] = $attributes;
            }
        }

        return $relations;
    }

    /**
     * Replicate a model instance, excluding attributes provided in the model's getDuplicateOptions() method.
     *
     * @return Model
     */
    private function duplicateModelWithExcluding(): Model
    {
        $except = [];
        $excluded = $this->duplicateOptions->excludedColumns;

        if ($this->usesTimestamps()) {
            $except = array_merge($except, [
                $this->getCreatedAtColumn(),
                $this->getUpdatedAtColumn(),
            ]);
        }

        if ($excluded && is_array($excluded) && ! empty($excluded)) {
            $except = array_merge($except, $excluded);
        }

        return $this->replicate($except);
    }

    /**
     * @param $original
     * @param $duplicated
     */
    private function addDuplicatedModelToCache($original, $duplicated)
    {
        if (get_class($original) !== get_class($duplicated)) {
            throw Exception(
                'Cannot add duplicated item to list, classnames not equal: ' .
                get_class($original) .
                ', ' .
                get_class($duplicated)
            );
        }

        $key = $duplicated->cacheKey;
        $duplicatedItems = Cache::get($key, []);
        $duplicatedItems[] = [
            'class' => get_class($original),
            'original_id' => $original->id,
            'duplicate_id' => $duplicated->id
        ];
        Cache::put($this->getCacheKey(), $duplicatedItems, now()->addMinutes(30));
    }

    /**
     * Update a model instance.
     * With unique values for the attributes provided in the model's getDuplicateOptions() method.
     *
     * @param Model $model
     * @return Model
     */
    private function duplicateModelWithUnique(Model $model): Model
    {
        $unique = $this->duplicateOptions->uniqueColumns;

        if (! $unique || ! is_array($unique) || empty($unique)) {
            return $model;
        }

        foreach ($unique as $column) {
            $i = 1;
            $original = $value = $model->{$column};

            while (static::withoutGlobalScopes()->where($column, $value)->first()) {
                $value = $original.' ('.$i++.')';

                $model->{$column} = $value;
            }
        }

        return $model;
    }

    /**
     * Replicate a model relation instance, excluding attributes provided in the model's getDuplicateOptions() method.
     *
     * @param Model $model
     * @param string $relation
     * @return Model
     */
    private function duplicateRelationWithExcluding(Model $model, string $relation): Model
    {
        $attributes = null;
        $excluded = $this->duplicateOptions->excludedRelationColumns;

        if ($excluded && is_array($excluded) && ! empty($excluded)) {
            if (array_key_exists($relation, $excluded)) {
                $attributes = $excluded[$relation];
            }
        }

        return $model->replicate($attributes);
    }

    /**
     * Update a relation for the model instance.
     * With unique values for the attributes attributes provided in the model's getDuplicateOptions() method.
     *
     * @param Model $model
     * @param string $relation
     * @return Model
     */
    private function duplicateRelationWithUnique(Model $model, string $relation): Model
    {
        $unique = $this->duplicateOptions->uniqueRelationColumns;

        if (! $unique || ! is_array($unique) || empty($unique)) {
            return $model;
        }

        if (array_key_exists($relation, $unique)) {
            foreach ($unique[$relation] as $column) {
                $i = 1;
                $original = $value = $model->{$column};

                while ($model->where($column, $value)->first()) {
                    $value = $original.' ('.$i++.')';

                    $model->{$column} = $value;
                }
            }
        }

        return $model;
    }

    /**
     * Get additional pivot attributes that should be saved when duplicating a pivoted relation.
     * Usually, these are attributes coming from the withPivot() method defined on the relation.
     *
     * @param Model $model
     * @return array
     */
    protected function establishDuplicatablePivotAttributes(Model $model): array
    {
        $pivot = $model->pivot;

        return Arr::except($pivot->getAttributes(), [
            $pivot->getKeyName(),
            $pivot->getForeignKey(),
            $pivot->getOtherKey(),
            $pivot->getCreatedAtColumn(),
            $pivot->getUpdatedAtColumn(),
        ]);
    }

    /**
     * Instantiate the duplicate options.
     *
     * @return void
     */
    protected function initDuplicateOptions(): void
    {
        if ($this->duplicateOptions === null) {
            $this->duplicateOptions = $this->getDuplicateOptions();
        }
    }
}
