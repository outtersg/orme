<?php
/*
 * Copyright (c) 2021 Guillaume Outters
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.  IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace Gui\ORME\Doctrine;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Id\SequenceGenerator;

class BatchSequenceGenerator extends SequenceGenerator
{
    protected $nextIds = [];

    public function __construct($sequenceName, $allocationSize)
    {
        parent::__construct($sequenceName, $allocationSize);
        $this->_sequenceName = $sequenceName;
    }

    public function isPostInsertGenerator($atInsert = false)
    {
        // We are a neither post nor pre generator:
        // when called on persist() we are a post, because we delay generation.
        // when called on insert() we are a pre, because we have to compute before inserting.
        // /!\ Only MassEntityPersister knows how to drive us correctly (calling us at pre-post-insert).
        return !$atInsert;
    }

    public function fetch(EntityManager $em, $count = 1)
    {
        $conn = $em->getConnection();
        $sql = "SELECT NEXTVAL('{$this->_sequenceName}') FROM generate_series(1, $count)";
        $all = $conn->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
        $this->nextIds = array_merge($this->nextIds, $all);
    }

    /**
     * {@inheritDoc}
     */
    public function generate(EntityManager $em, $entity)
    {
        // We have to memorize if we've already IDed this entity, in case generate() is called twice on it.
        // This happens for example if the MassEntityPersister (which calls generate() before inserting) decides to
        // fall back to its parent (which calls generate() after insertion, as a postInsertGenerator).
        if (isset($entity->__batchSequenceId)) {
            return $entity->__batchSequenceId;
        }
        return $entity->__batchSequenceId = count($this->nextIds) ? array_shift($this->nextIds) : parent::generate($em, $entity);
    }
}

?>
