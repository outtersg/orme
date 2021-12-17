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

use Doctrine\Common\Persistence\Mapping\ClassMetadataFactory;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\Persisters\Entity\JoinedSubclassPersister;
use Doctrine\ORM\Persisters\Entity\SingleTablePersister;
use Doctrine\ORM\UnitOfWork;

/**
 * Utility for using MassEntityPersister in a Doctrine EntityManager.
 */
class MassEntityPersisterFactory
{
    /**
     * Replaces the hard-coded BasicEntityPersister in UnitOfWork.
     * (hack until UnitOfWork uses a factory to fetch its persisters)
     *
     * @todo Ensure the EM's UnitOfWork is clean when replacing it.
     */
    public static function AsBasicEntityPersister(EntityManager $em)
    {
        if ($em->getUnitOfWork() instanceof HackyUnitOfWork) {
            return;
        }
        // @todo Refuse to replace a dirty UnitOfWork.

        $rem = new \ReflectionClass($em);

        $u = new HackyUnitOfWork(
            $em,
            '\Gui\ORME\Doctrine\MassEntityPersister',
            '\Gui\ORME\Doctrine\BatchSingleTablePersister'
        );
        $ruow = $rem->getProperty('unitOfWork');
        $ruow->setAccessible(true);
        $ruow->setValue($em, $u);

        $cmf = new HackyClassMetadataFactory($em->getMetadataFactory(), $u);
        $rcmf = $rem->getProperty('metadataFactory');
        $rcmf->setAccessible(true);
        $rcmf->setValue($em, $cmf);
    }
}

class HackyUnitOfWork extends UnitOfWork
{
    protected $em;
    protected $persisters = [];

    public function __construct(
        EntityManagerInterface $em,
        $basicEntityPersisterClass = '\Doctrine\ORM\Persisters\Entity\BasicEntityPersister',
        $singleTablePersisterClass = '\Doctrine\ORM\Persisters\Entity\SingleTablePersister'
    ) {
        parent::__construct($em);
        $this->em = $em;
        $this->bepClass = $basicEntityPersisterClass;
        $this->stpClass = $singleTablePersisterClass;

        $rclass = new \ReflectionClass($this);
        $rclass = $rclass->getParentClass();

        $this->reflEntIds = $rclass->getProperty('entityIdentifiers');
        $this->reflEntIds->setAccessible(true);
        if (!method_exists($this, 'hasMissingIdsWhichAreForeignKeys')) {
            $this->_foreignKeyIdClasses = [];
        }
    }

    public function getEntityPersister($entityName)
    {
        if (isset($this->persisters[$entityName])) {
            return $this->persisters[$entityName];
        }

        $class = $this->em->getClassMetadata($entityName);

        switch (true) {
            case $class->isInheritanceTypeNone():
                $bepClass = $this->bepClass;
                $persister = new $bepClass($this->em, $class);
                break;

            case $class->isInheritanceTypeSingleTable():
                $stpClass = $this->stpClass;
                $persister = new $stpClass($this->em, $class);
                break;

            case $class->isInheritanceTypeJoined():
                $persister = new JoinedSubclassPersister($this->em, $class);
                break;

            default:
                throw new RuntimeException('No persister found for entity.');
        }

        if ($this->hasCache && $class->cache !== null) {
            $persister = $this->em->getConfiguration()
                ->getSecondLevelCacheConfiguration()
                ->getCacheFactory()
                ->buildCachedEntityPersister($this->em, $persister, $class);
        }

        $this->persisters[$entityName] = $persister;

        // Hack it to the damnedly private parent::persisters, so that other UnitOfWork's methods are aware of it.
        $rclass = new \ReflectionClass($this);
        $rclass = $rclass->getParentClass();
        $rpers = $rclass->getProperty('persisters');
        $rpers->setAccessible(true);
        $rpers->setValue($this, $this->persisters);

        return $this->persisters[$entityName];
    }

    /*- Doctrine < 2.6: handle primary foreign keys --------------------------*/
    /* If one of our optimized entities is used as a foreign but primary key to another entity, our optimization gets
     * broken. */

    public function scheduleForInsert($entity)
    {
        /* Doctrine (observed on versions 2.5 to 2.10):
         * We should NOT allow an already-persisted object to be reinserted.
         * This can happen when the user cleared us, but kept a reference to an entity that it repersists as new.
         *   In case of a SequenceGenerator or IdentityGenerator, it will create a duplicate
         *     (the memory object gets a new ID and is reinserted with its last-fetched fields, except the ID that changes)
         *   In case of an AssignedGenerator the DBMS will throw us a duplicate key
         *     (we reinsert exactly the same values, including the ID)
         * THIS HAPPENS DUE TO A DEVELOPER'S ERROR
         *   but we can be kind and notify their mistake.
         * a. Detecting it is not easy on preInsertGenerators (apart from querying the DB),
         * b. could be easier for postInsertGens (if the entity already has an ID when scheduledForInsert()),
         * c. and we have a really easy case with BatchSequenceGenerator.
         * So for now only handle c.
         */
        if (isset($entity->__batchSequenceId)) {
            // We can either:
            // 1. let go (current Doctrine behaviour): let the DB error or create a duplicate
            // 2. throw, to make the developer aware
            // 3. or ignore the insert, just registerManaged(). But this would require a fetch to make sure the entity
            //    is there (and if it isn't, clear the ID then let go)
            throw ORMInvalidArgumentException::scheduleInsertForManagedEntity($entity);
        }

        /* Ensure we have not put an empty foreign key, on old Doctrine versions (< 2.6) missing
         * hasMissingIdsWhichAreForeignKeys().
         * See https://github.com/doctrine/orm/commit/35c3669ebc822c88444d92e9ffc739d12f551d46
         */
        if (isset($this->_foreignKeyIdClasses)) {
            $className = get_class($entity);
            $class = $this->em->getClassMetadata($className);
            if (!isset($this->_foreignKeyIdClasses[$className])) {
                $this->_foreignKeyIdClasses[$className] = $this->classForeignKeyFields($class);
            }
            if ($this->_foreignKeyIdClasses[$className]) {
                $entityIdentifiers = $this->reflEntIds->getValue($this);
                $oid = spl_object_hash($entity);
                foreach ($this->_foreignKeyIdClasses[$className] as $idField) {
                    if (!isset($entityIdentifiers[$oid][$idField])) {
                        unset($entityIdentifiers[$oid]);
                        $this->reflEntIds->setValue($this, $entityIdentifiers);
                        break;
                    }
                }
            }
        }
        return parent::scheduleForInsert($entity);
    }

    protected function classForeignKeyFields($class)
    {
        $fkeys = [];
        foreach ($class->identifier as $fieldName) {
            if (isset($class->associationMappings[$fieldName])) {
                $fkeys[] = $fieldName;
            }
        }
        return empty($fkeys) ? false : $fkeys;
    }
}

/**
 * A proxy to ClassMetadataFactory that knows how to interact with the EntityPersister to get it an optimized
 * SequenceGenerator.
 */
class HackyClassMetadataFactory implements ClassMetadataFactory
{
    protected $loadedMetadata = [];

    public function __construct($impl, $uow)
    {
        $this->impl = $impl;
        $this->uow = $uow;
    }

    public function isTransient($class)
    {
        return $this->impl->isTransient($class);
    }

    public function setMetadataFor($className, $class)
    {
        unset($this->loadedMetadata[$className]);
        $this->impl->setMetadataFor($className, $class);
    }

    public function getMetadataFor($className)
    {
        if (isset($this->loadedMetadata[$className])) {
            return $this->loadedMetadata[$className];
        }

        $class = $this->loadedMetadata[$className] = $this->impl->getMetadataFor($className);

        // Now we immediately call UOW's persister for class: in case it is a MassEntityPersister, it will know
        // how to drive a BatchSequenceGenerator and replace any SequenceGenerator with a Batch one (if configured to).
        // The persister's constructor will detect if it can drive it, and then plug it directly into the $class.
        $this->uow->getEntityPersister($className);

        return $class;
    }

    public function getLoadedMetadata()
    {
        return $this->loadedMetadata;
    }

    public function getAllMetadata()
    {
        return $this->impl->getAllMetadata();
    }

    public function hasMetadataFor($className)
    {
        return isset($this->loadedMetadata[$className]) ? true : $this->impl->hasMetadataFor($className);
    }

    public function getCacheDriver()
    {
        return $this->impl->getCacheDriver();
    }
}

?>
