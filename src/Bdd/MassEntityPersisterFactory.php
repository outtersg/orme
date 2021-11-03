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

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
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
        $u = new HackyUnitOfWork($em, '\Gui\ORME\Bdd\MassEntityPersister');

        $rem = new \ReflectionClass($em);
        $ruow = $rem->getProperty('unitOfWork');
        $ruow->setAccessible(true);
        $ruow->setValue($em, $u);
    }
}

class HackyUnitOfWork extends UnitOfWork
{
    protected $em;
    protected $persisters = [];

    public function __construct(EntityManagerInterface $em, $basicEntityPersisterClass = '\Doctrine\ORM\Persisters\Entity\BasicEntityPersister')
    {
        parent::__construct($em);
        $this->em = $em;
        $this->bepClass = $basicEntityPersisterClass;
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
                $persister = new SingleTablePersister($this->em, $class);
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
}

?>
