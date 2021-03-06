<?php

namespace ClosureCode\Queryable\Traits;

use Illuminate\Database\Eloquent\Builder;

trait Queryable
{
    private $queryPattern = '/([!=|<=|<|>=|>|=|!~|~])/m';
    private $queries = [];

    public function scopeWithQueryables($query)
    {
        $this->parseQueryParams($query);

        return $query;
    }

    public function setQueryable(array $queryable)
    {
        $this->queryable = $queryable;

        return $this;
    }

    public function addQueryable($column)
    {
        $this->queryable[] = $column;

        return $this;
    }

    public function removeQueryable($colum)
    {
        $index = array_search($column, $this->queryable);

        if ($index !== false) {
            unset($this->queryable[$index]);
        }

        return $this;
    }

    public function makeAllQueryable()
    {
        $this->queryable = ['*'];

        return $this;
    }

    public function clearQueryable()
    {
        $this->queryable = [];

        return $this;
    }

    private function parseQueryParams($queryBuilder)
    {
        $queryString = urldecode(request()->getQueryString());
        $queryStringSplit = explode('&', $queryString);

        foreach ($queryStringSplit as $query) {
            $this->parseQueryString($query, $queryBuilder);
        }

        foreach ($this->queries as $query) {
            if (!isset($query->value)) {
                // whereNull, whereNotNull
                $queryBuilder->{$query->method}($query->key);
            } elseif (!isset($query->operator)) {
                // whereIn, whereNotIn, or orderBy
                $queryBuilder->{$query->method}($query->key, $query->value);
            } else {
                $queryBuilder->{$query->method}($query->key, $query->operator, $query->value);
            }
        }

        $this->queries = [];
    }

    private function parseQueryString($string, $queryBuilder)
    {
        $queryMatch = preg_split($this->queryPattern, $string, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $queryMatch = $this->fixQueryOperator($queryMatch);

        if ($queryMatch) {
            $this->queries[] = $queryMatch;
        }
    }

    private function fixQueryOperator($queryMatch)
    {
        if (count($queryMatch) >= 3 && $this->isValidParam($queryMatch[0])) {
            $subQueries = [];

            if (count($queryMatch) > 3) {
                $queryMatch[1] .= $queryMatch[2];
                unset($queryMatch[2]);
                $queryMatch = array_values($queryMatch);
            }

            return $this->parseQueryMatch($queryMatch);
        }

        return false;
    }

    private function parseQueryMatch($queryMatch)
    {
        $object = (object) [
          'key'       => snake_case($queryMatch[0]),
          'method'    => 'where',
          'operator'  => $queryMatch[1],
          'value'     => $queryMatch[2],
          'arg'       => '',
        ];

        if (in_array($object->operator, ['=', '!=', '<', '>', '<=', '>='])) {
            if ($object->value == 'null') {
                $object->method = ($object->operator == '=' ? 'whereNull' : 'whereNotNull');
                unset($object->value);
            } elseif (str_contains($object->value, '*')) {
                if (starts_with($object->value, '*')) {
                    $object->operator = 'like';
                    $object->value = '%' . substr($object->value, 1);
                }
                if (ends_with($object->value, '*')) {
                    $object->operator= 'like';
                    $object->value = substr($object->value, 0, -1) . '%';
                }
            } elseif ($object->key == 'order_by') {
                $pars = explode(',', $object->value);
                $pars[0] = snake_case($pars[0]);

                if ($this->isValidParam($pars[0])) {
                    $object->method = 'orderBy';
                    $object->key = $pars[0];
                    $object->value = 'asc';

                    if (count($pars) == 2) {
                        $object->value = strtolower($pars[1]);
                    }

                    unset($object->operator);
                } else {
                    return false;
                }
            }

            return $object;
        } elseif ($object->operator == '!~' || $object->operator == '~') {
            $object->value = str_contains($object->value, ',') ? explode(',', $object->value) : [$object->value];
            $object->method = ($object->operator == '!~' ? 'whereNotIn' : 'whereIn');
            unset($object->operator);
            return $object;
        }

        return false;
    }

    private function isValidParam($param)
    {
        if ($this->queryable[0] == '*') {
            return true;
        }

        return in_array($param, $this->queryable) && !in_array($param, $this->hidden);
    }
}
