<?php

namespace Zbiller\Duplicate\Options;

use Exception;

class DuplicateOptions
{
    /**
     * The database columns that should be ignored when duplicating the record.
     *
     * @var array|string
     */
    private $excludedColumns;

    /**
     * The database columns that should be unique when duplicating the record.
     *
     * @var array|string
     */
    private $uniqueColumns;

    /**
     * The relations of the model that should be ignored when duplicating the record.
     *
     * @var array|string
     */
    private $excludedRelations;

    /**
     * The database columns for each model's relation that should be ignored when duplicating the record.
     *
     * @var array
     */
    private $excludedRelationColumns;

    /**
     * The database columns for each model's relation that should be unique when duplicating the record.
     *
     * @var array
     */
    private $uniqueRelationColumns;

    /**
     * Flag indicating if when duplicating a record, the script should also duplicate it's relations.
     *
     * @var bool
     */
    private $shouldDuplicateDeeply = true;

    /**
     * Get the value of a property of this class.
     *
     * @param $name
     * @return mixed
     * @throws Exception
     */
    public function __get($name)
    {
        if (property_exists(static::class, $name)) {
            return $this->{$name};
        }

        throw new Exception(
            'The property "' . $name . '" does not exist in class "' . static::class . '"'
        );
    }

    /**
     * Get a fresh instance of this class.
     *
     * @return DuplicateOptions
     */
    public static function instance(): DuplicateOptions
    {
        return new static();
    }

    /**
     * Set the $excludedColumns to work with in the Zbiller\Duplicate\Traits\HasDuplicates trait.
     *
     * @param array|string $columns
     * @return DuplicateOptions
     */
    public function excludeColumns(...$columns): DuplicateOptions
    {
        $this->excludedColumns = array_flatten($columns);

        return $this;
    }

    /**
     * Set the $uniqueColumns to work with in the Zbiller\Duplicate\Traits\HasDuplicates trait.
     *
     * @param array|string $columns
     * @return DuplicateOptions
     */
    public function uniqueColumns(...$columns): DuplicateOptions
    {
        $this->uniqueColumns = array_flatten($columns);

        return $this;
    }

    /**
     * Set the $excludedRelations to work with in the Zbiller\Duplicate\Traits\HasDuplicates trait.
     *
     * @param array|string $relations
     * @return DuplicateOptions
     */
    public function excludeRelations(...$relations): DuplicateOptions
    {
        $this->excludedRelations = array_flatten($relations);

        return $this;
    }

    /**
     * Set the $excludedRelationColumns to work with in the Zbiller\Duplicate\Traits\HasDuplicates trait.
     *
     * Param $columns:
     * --- associative array with keys containing each relation name and values (array) containing the excluded columns for each relation.
     *
     * @param array $columns
     * @return DuplicateOptions
     */
    public function excludeRelationColumns(array $columns = []): DuplicateOptions
    {
        $this->excludedRelationColumns = $columns;

        return $this;
    }

    /**
     * Set the $uniqueRelationColumns to work with in the Zbiller\Duplicate\Traits\HasDuplicates trait.
     *
     * Param $columns:
     * --- associative array with keys containing each relation name and values (array) containing the unique columns for each relation.
     *
     * @param array $columns
     * @return DuplicateOptions
     */
    public function uniqueRelationColumns(array $columns = []): DuplicateOptions
    {
        $this->uniqueRelationColumns = $columns;

        return $this;
    }

    /**
     * Set the $shouldDuplicateDeeply to work with in the Zbiller\Duplicate\Traits\HasDuplicates trait.
     *
     * @return DuplicateOptions
     */
    public function disableDeepDuplication(): DuplicateOptions
    {
        $this->shouldDuplicateDeeply = false;

        return $this;
    }
}
