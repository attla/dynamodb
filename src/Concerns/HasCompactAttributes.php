<?php

namespace Attla\Dynamodb\Concerns;

use Illuminate\Support\Arr;

trait HasCompactAttributes
{
    /**
     * Store the compacted attributes
     *
     * @var array<mixed>
     */
    protected $_c = [];

    /**
     * Store sub attributes key map
     *
     * @var array<string, mixed>
     */
    public $_k = [];

    /**
     * Store fillable attributes
     *
     * @var array<int, string>
     */
    public $_f = [];

    /**
     * Replace maps of types
     *
     * @var array<string, string>
     */
    protected $typeMap = [
        // Null
        'null,' => 'N,',
        ',null' => ',N',
        'null]' => 'N]',
        // True
        'true,' => 'T,',
        ',true' => ',T',
        'true]' => 'T]',
        // False
        'false,' => 'X,',
        ',false' => ',X',
        'false]' => 'X]',
        // Array/Object
        '[],' => 'O,',
        ',[]' => ',O',
        '[]]' => 'O]',
    ];

    /**
     * Json options for encode/decode
     *
     * @var int
     */
    protected $jsonOptions = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    // LARAVEL Eloquent rewrites
    /** @inheritdoc */
    public function getFillable()
    {
        if (!empty($this->_f)) {
            return $this->_f;
        }

        return $this->_f = array_map(function($key, $value) {
            if (is_int($key)) {
                return $value;
            }

            $this->_k[$key] = $value;
            return $key;
        }, array_keys($this->fillable), $this->fillable);
    }

    /**
     * Flatten array of field mapped keys
     *
     * @param array $fields
     * @param string|null $path
     * @return array
     */
    protected function processFields($fields, $path = null)
    {
        $index = -1;
        $result = [];
        foreach ($fields as $key => $val) {
            $index++;
            $cPath = ($path !== null ? $path.'.' : '').$key;

            if ($key === $index) {
                $result[$key] = is_array($val) ? $this->processFields($val, $cPath) : $val;
                continue;
            }

            if (is_array($val)) {
                $result[$index] = $key;
                $val = $this->processFields($val, $cPath);
            }

            $result[$cPath] = $val;
        }

        return $result;
    }

    /**
     * Boot compact attributes
     *
     * @return void
     */
    public static function bootHasCompactAttributes()
    {
        static::building(function ($model) {
            $model->fillable(array_merge($model->getFillable(), $model->timestamps()));
        });

        static::builded(function ($model) {
            $model->hidden[] = 'v';
            $model->guard(array_merge($model->getGuarded(), $model->timestamps()));

        });

        $prepare = fn($event) => fn ($model) => $model->prepareCompacts($event);
        static::creating($prepare('create'));
        static::updating($prepare('update'));

        $load = fn ($model) => $model->loadCompacts();
        static::retrieved($load);
        static::created($load);
        static::updated($load);
    }

    /**
     * Load compact attribute on model
     *
     * @return void
     */
    public function loadCompacts()
    {
        if (!empty($value = $this->attributes['v'] ?? [])) {
            $value = $this->decodeValue($value);

            $fillable = $this->fields();

            foreach ($fillable as $index => $field) {
                $val = $value[$index] ?? null;
                $this->$field = Arr::has($this->_k, $field) ? $this->retrieveNames($this->_k[$field], $val, $field) : $val;
            }
        }
    }

    /**
     * Transforms array of key-value pairs into an object
     *
     * @param array $entries
     * @return array
     */
    protected function fromEntries(array $entries): array {
        return array_reduce($entries, function($acc, $entry) {
            if (is_array($entry) && count($entry) == 2 && isset($entry[0]) && $entry[0] !== null) {
                $acc[$entry[0]] = $entry[1] ?? null;
            } else if (is_array($entry) && !isset($entry[0], $entry[1])) {
                $acc[] = $entry;
            }

            return $acc;
        }, []);
    }

    /**
     * Retrieve labels of a array zip
     *
     * @param array<int|string, string|array<int|string.string> $keys
     * @param mixed|null $value
     * @param string|null $key
     * @return array
     */
    protected function retrieveNames($keys, $value = null, $key = null)
    {
        if (!is_array($keys) || empty($value)) {
            return $value;
        }

        if ($key === null){
            $keys = $this->processFields($keys);
        }

        if (!is_array($value)) {
            return [Arr::get($keys, $key), $value];
        }

        return $this->fromEntries(array_map(function ($index, $val) use ($keys, $key) {
            $path = ($key !== null ? $key.'.' : '').$index;
            $column = $matrix = Arr::has($keys, $path) ? $path : $index;

            while (Arr::has($keys, $matrix)) {
                $column = Arr::get($keys, $matrix);
                if (is_array($column)) {
                    $index = $matrix;
                    $path = $index;
                    break;
                } else if ($column == $matrix) {
                    break;
                }

                $matrix = $column;
            }

            if (is_array($val)) {
                $matrix = Arr::has($keys, $path) ? $path : $matrix;
                return [$index, $this->retrieveNames($keys, $val, $matrix)];
            }

            return [$matrix, $val];
        }, array_keys($value), $value));
    }

    /**
     * Get fillable timestamps columns
     *
     * @return array<string>
     */
    protected function timestamps()
    {
        return array_filter([static::CREATED_AT, static::UPDATED_AT], fn ($col) => is_string($col));
    }

    /**
     * Get fillable attributes columns
     *
     * @return array<string>
     */
    protected function fields()
    {
         return array_values(array_diff(
            array_unique(array_merge($this->getFillable(), $timestamps = $this->timestamps())),
            array_merge($this->getKeySchema(), array_values(array_diff($this->getGuarded(), $timestamps)))
        ));
    }

    /**
     * Prepare the compact attributes to persiste on database
     *
     * @param string|null $event
     * @return void
     */
    public function prepareCompacts(string|null $event = null)
    {
        $data = [];
        $fillable = $this->fields();
        $this->attributes = array_merge($this->_c, $this->attributes);

        if ($event === 'create' && in_array(static::CREATED_AT, $fillable)) {
            $this->updateTimestamps();
            $this->setUpdatedAt(null);
        } else if ($event === 'update') {
            $this->syncOriginal();
            $this->updateTimestamps();
            $this->fillable = array_values(array_diff($this->fillable, $this->timestamps()));
        }

        $array = $this->toArray();
        foreach ($fillable as $field) {
            $data[] = $array[$field] ?? null;
        }

        $this->_c = $this->attributes;
        $this->attributes = [
            'v' => $this->encodeValue($data),
            'pk' => $this->pk,
            'sk' => $this->sk,
        ];
    }

    /**
     * Get the name of the "created at" column
     *
     * @return string|null
     */
    public function getCreatedAtColumn()
    {
        return in_array($column = static::CREATED_AT, $this->fillable) ? $column : null;
    }

    /**
     * Get the name of the "updated at" column
     *
     * @return string|null
     */
    public function getUpdatedAtColumn()
    {
        return in_array($column = static::UPDATED_AT, $this->fillable) ? $column : null;
    }

    /**
     * Maps values ​​for compression to save on database
     *
     * @param mixed $value
     * @return mixed
     */
    protected function encodeValue($value)
    {
        $value = $this->zipArray($value);
        $value = json_encode($value, $this->jsonOptions);

        $value = preg_replace('/(,|\[)"(\^\d+)"(\]|,|$)/', '$1$2$3', $value);

        $value = str_replace('"', $dq = '~TDQ~', $value);
        $value = str_replace("'", '"', $value);
        $value = str_replace($dq, "'", $value);
        $value = str_replace("\'", "^'", $value);

        return strtr($value, $this->typeMap);
    }

    /**
     * Converts compressed values ​​back to their original format
     *
     * @param mixed $value
     * @return mixed
     */
    protected function decodeValue($value)
    {
        $value = strtr($value, array_flip($this->typeMap));

        $value = str_replace('"', $sq = '~TSQ~', $value);
        $value = str_replace("'", '"', $value);
        $value = str_replace($sq, "'", $value);
        $value = str_replace('^"', '\"', $value);
        $value = preg_replace('/(,|\[)(\^\d+)(\]|,|$)/', '$1"$2"$3', $value);

        $value = json_decode($value, true, 512, $this->jsonOptions);

        return $this->unzipArray($value);
    }

    /**
     * Zip the attribute array
     *
     * @param array $array
     * @param array &$seen
     * @return array
     */
    protected function zipArray(array $array, &$seen = []): array {
        $result = [];

        foreach ($array as $index => $item) {
            if (is_array($item)) {
                $result[] = $this->zipArray($item, $seen);
                continue;
            }

            $length = match (gettype($item)) {
                'string' => strlen($item),
                'array' => count($item),
                'stdClass', 'object' => count(get_object_vars($item)),
                'integer', 'double', 'float' => strlen((string) $item),
                default => 0,
            };

            if ($length > 2 && ($pos = array_search($item, $seen, true)) !== false) {
                $result[] = "^$pos";
                continue;
            }

            if (!in_array($item, [null, true, false], true)) {
                $seen[] = $item;
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * Unzip the attribute array
     *
     * @param array $array
     * @param array &$seen
     * @param bool $deep
     * @return array
     */
    protected function unzipArray(array $array, &$seen = [], $deep = false): array {
        foreach ($array as &$item) {
            if (in_array($item, [null, true, false], true)) {
                continue;
            }

            if (is_array($item)) {
                $item = $this->unzipArray($item, $seen, true);
                continue;
            }

            $pos = is_string($item) && str_starts_with($item, '^')
                ? (int) substr($item, 1)
                : -1;

            if ($pos < 0) {
                $seen[] = $item;
                continue;
            }

            if (!$deep && $pos > -1 && isset($array[$pos]) && !str_starts_with($val = $array[$pos], '^')) {
                $item = $val;
            } else if ($pos > -1 && isset($seen[$pos]) && !str_starts_with($val = $seen[$pos], '^')) {
                $item = $val;
            }
        }

        return $array;
    }
}
