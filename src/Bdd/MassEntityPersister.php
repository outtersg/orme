<?php
/*
 * Copyright (c) 2017,2021 Guillaume Outters
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

namespace Gui\ORME\Bdd;

use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;

class MassEntityPersister extends BasicEntityPersister
{
    protected $inserter;

    protected $massThreshold = 3;

    /**
     * {@inheritdoc}
     */
    public function executeInserts()
    {
        if ( ! $this->queuedInserts) {
            return [];
        }

        if (count($this->queuedInserts) < $this->massThreshold) {
            return parent::executeInserts();
        }

        $postInsertIds  = [];
        $idGenerator    = $this->class->idGenerator;
        $isPostInsertId = $idGenerator->isPostInsertGenerator();

        // Two conditions on mass inserting:
        // - having the driver offer the feature
        // - having a unique column helping us to associate the input object with the created DB entry
        //   Of course a (pre-filled) ID does exactly that, so we require it as a shortcut

        $db = $this->conn->getWrappedConnection();
        if ($isPostInsertId || !($db instanceof \PDO) || $db->getAttribute(\PDO::ATTR_DRIVER_NAME) != 'pgsql') {
            // @todo Notice that the optimized version is not available.
            return parent::executeInserts();
        }

        $stmt       = $this->conn->prepare($this->getInsertSQL());
        $tableName  = $this->class->getTableName();
        $tableData  = [];

        foreach ($this->queuedInserts as $entity) {
            $insertData = $this->prepareInsertData($entity);
            if (isset($insertData[$tableName])) {
                $tableData[] = $insertData[$tableName];
            }
        }

        if (!isset($this->inserter)) {
            $this->inserter = new Majeur();
        }
        $tableFqdn = ($this->class->getSchemaName() ? $this->class->getSchemaName().'.' : '').$tableName;
        if (!$this->inserter->insérer($db, $tableFqdn, $tableData)) {
            return parent::executeInserts();
        }

        if ($this->class->isVersioned) {
            foreach ($this->queuedInserts as $entity) {
                $id = $this->class->getIdentifierValues($entity);
                $this->assignDefaultVersionValue($entity, $id);
            }
        }

        $this->queuedInserts = [];

        return $postInsertIds;
    }
}

?>