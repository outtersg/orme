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

namespace Gui\ORME\Doctrine;

use Gui\ORME\Bdd\Majeur;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\ORM\Utility\PersisterHelper;

class MassEntityPersister extends BasicEntityPersister
{
    protected $inserter;

    protected $massThreshold = 3;

    /**
     * Cache of our legitimacy to handle entities of each encountered raw PHP class.
     *
     * @var array
     */
    protected $handledClasses = [];

    public function __construct(EntityManagerInterface $em, ClassMetadata $class)
    {
        parent::__construct($em, $class);

        $this->canDelete = true;
        $class      = $this->class;
        $idColumns  = $this->quoteStrategy->getIdentifierColumnNames($class, $this->platform);
        if (count($idColumns) > 1) {
            // @todo Perhaps we could handle. Some DBs accept a where (col1, col2) in ((val1, val2), (val3, val4)).
            $this->canDelete = false;
        }
        foreach ([ 'executeStatement', 'executeUpdate' ] as $connUpdater) {
            if (method_exists($this->conn, $connUpdater)) {
                $this->connUpdater = $connUpdater;
                break;
            }
        }
        // @todo Have two whitelists (canDelete and canInsert), that are either an *, or a list of handled classes.
    }

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

    /**
     * {@inheritdoc}
     */
    public function delete($entity)
    {
        if (!$this->canDelete) {
            return parent::delete($entity);
        }

        // On first run, identify to-be-deleted entities that share our class.
        if (!isset($this->entityDeletions)) {
            $this->entityDeletions = [];
            foreach ($this->em->getUnitOfWork()->getScheduledEntityDeletions() as $entityToDelete) {
                $entityClass = get_class($entityToDelete);
                if (!isset($this->handledClasses[$entityClass])) {
                    $this->handledClasses[$entityClass] = $this->em->getClassMetadata($entityClass)->name === $this->class->name;
                }
                if ($this->handledClasses[$entityClass]) {
                    $identifier = $this->em->getUnitOfWork()->getEntityIdentifier($entityToDelete);
                    $this->entityDeletions[] = $identifier;
                }
            }
            $this->entityDeletionsCount = count($this->entityDeletions);
        }

        // If we're not the last, skip.
        if (--$this->entityDeletionsCount > 0) {
            return true;
        }

        if (count($this->entityDeletions) == 1) {
            unset($this->entityDeletions);
            return parent::delete($entity);
        }

        $class      = $this->class;
        $tableName  = $this->quoteStrategy->getTableName($class, $this->platform);
        $idColumns  = $this->quoteStrategy->getIdentifierColumnNames($class, $this->platform);
        $types      = $this->getClassIdentifiersTypes($class);

        foreach ($this->entityDeletions as $identifier) {
            $this->deleteJoinTableRecords($identifier);
        }

        // @todo Handle multi-column identifiers (and modify canDelete in constructor).
		// @todo Allow a paquet size upper limit (for array_chunking at 1000 for e.g. Oracle).

        foreach ($this->entityDeletions as & $ptrId) {
            $ptrId = $ptrId[$idColumns[0]];
        }
        $paramMarks = '?' . str_repeat(',?', count($this->entityDeletions) - 1);
        $connUpdater = $this->connUpdater;
        $result = (bool) $this->conn->$connUpdater(
            'DELETE FROM ' . $tableName . ' WHERE ' . $idColumns[0] . ' IN (' . $paramMarks . ')',
            $this->entityDeletions,
            array_fill(0, count($this->entityDeletions), $types[0])
        );
        unset($this->entityDeletions);
        return $result;
    }

    /**
     * Pure copy of parent's method, for compatibility for old versions of BasicEntityPersister missing it.
     * @return string[]
     */
    protected function getClassIdentifiersTypes(ClassMetadata $class) : array
    {
        $entityManager = $this->em;

        return array_map(
            static function ($fieldName) use ($class, $entityManager) : string {
                $types = PersisterHelper::getTypeOfField($fieldName, $class, $entityManager);
                assert(isset($types[0]));

                return $types[0];
            },
            $class->identifier
        );
    }
}

?>
