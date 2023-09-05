<?php
/*
 * Copyright (c) 2023 Guillaume Outters
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

use Doctrine\ORM\EntityManagerInterface;

class RelatedLoader
{
    /**
     * Simule le loadRelated mentionné dans https://stackoverflow.com/a/4396594/1346819 (Doctrine 1?).
     * Permet de charger les relations d'une liste d'entités en une fois, plutôt que de boucler sur la liste pour
     * récupérer la relation entrée par entrée.
     * $liste s'utilise ensuite normalement (foreach ($liste as $e) $e->getRelation()->…).
     *
     * @param Collection|array $liste
     * @param string $rel
     * @param ?EntityManagerInterface $em Si $liste est une PersistentCollection, il est inutile de passer l'$em ici, il
     *                                    sera prélevé de $liste.
     */
    public static function loadRelated($liste, string $rel, ?EntityManagerInterface $em = null): void
    {
        if (!count($liste)) {
            return;
        }

        // On suppose toute la liste sur le même modèle.
        foreach ($liste as $elem) {
            $classe = get_class($elem);
            break;
        }

        $ids = [];
        foreach ($liste as $elem) {
            $ids[] = $elem->getId();
        }

        if (!isset($em)) {
            // Vraiment le plaisir de rendre opaque.
            $rListe = new \ReflectionObject($liste);
            $rEm = $rListe->getProperty('em');
            $rEm->setAccessible(true);
            $em = $rEm->getValue($liste);
        }

        $qb = $em->createQueryBuilder();
        $qb
            ->select('partial e.{id}, r')
            ->from($classe, 'e')
            ->leftJoin('e.'.$rel, 'r')
            ->where('e.id in (:ids)')
            ->setParameter('ids', $ids)
        ;
        $qb->getQuery()->getResult();
    }
}
