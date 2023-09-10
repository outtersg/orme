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

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;

trait RelatedLoader
{
    /**
     * Simule le loadRelated mentionné dans https://stackoverflow.com/a/4396594/1346819 (Doctrine 1?).
     * Permet de charger les relations d'une liste d'entités en une fois, plutôt que de boucler sur la liste pour
     * récupérer la relation entrée par entrée.
     * $liste s'utilise ensuite normalement (foreach ($liste as $e) $e->getRelation()->…).
     *
     * @param Collection|array $liste
     * @param string|array $rels
     * @param ?EntityManagerInterface $em Si $liste est une PersistentCollection, il est inutile de passer l'$em ici, il
     *                                    sera prélevé de $liste.
     * @param bool $retourAPlat Si faux (par défaut), renvoie $liste enrichie. Si vrai, renvoie côte-à-côte les objets
     *                          liés dénichés.
     *
     * @return Collection Soit la version Collection de $liste, soit (si $retourAPlat) le tableau à plat de toutes les relations trouvées.
     *                    Si $rels comporte plusieurs relations, c'est la première qui sera exploitée.
     *                    Si plusieurs éléments de $liste sont liés à la même entité, celle-ci sera renvoyée en
     *                    plusieurs exemplaires.
     */
    public static function loadRelatedFor($liste, $rels, ?EntityManagerInterface $em = null, bool $retourAPlat = false): Collection
    {
        if (!count($liste)) {
            return new ManagedCollection($em, []);
        }

        $classes = [];
        foreach ($liste as $elem) {
            $classes[get_class($elem)] = true;
        }
        if (count($classes) != 1) {
            throw new \Exception('loadRelated ne sait pas traiter les liste mixtes ('.implode(', ', array_keys($classes)).')');
        }
        $classe = array_keys($classes)[0];

        if (!isset($em)) {
            // Vraiment le plaisir de rendre opaque.
            $rListe = new \ReflectionObject($liste);
            $rEm = $rListe->getProperty('em');
            $rEm->setAccessible(true);
            $em = $rEm->getValue($liste);
        }

        $meta = $em->getClassMetadata($classe);

        $ids = [];
        foreach ($liste as $elem) {
            $id = $meta->getIdentifierValues($elem);
            if (!isset($champId)) {
                if (count($id) != 1) {
                    throw new \Exception('loadRelated ne gère que les entités à clé mono-champ');
                }
                $champId = array_keys($id)[0];
            }
            $ids[$id[$champId]] = true;
        }
        $ids = array_keys($ids);

        $qb = $em->createQueryBuilder();
        $qb
            ->select('partial _.{'.$champId.'}')
            ->from($classe, '_')
            ->where('_.'.$champId.' in (:ids)')
            ->setParameter('ids', $ids)
        ;
        foreach (is_array($rels) ? $rels : [ $rels ] as $alias => $rel) {
            if (is_numeric($alias)) {
                $alias = '_'.$alias;
            }
            $qb->leftJoin((strpos($rel, '.') !== false ? '' : '_.').$rel, $alias);
            $qb->addSelect($alias);
        }
        $r = $qb->getQuery()->getResult();
        if ($retourAPlat) {
            $rt = [];
            if (is_array($rel = $rels)) {
                foreach ($rels as $rel) { break; }
            }
            foreach ($r as $root) {
                $fils = $meta->getFieldValue($root, $rel);
                $rt = array_merge($rt, is_array($fils) ? $fils : $fils->toArray());
                // @todo? Une seule occurrence si une même entrée est référencée depuis deux de $liste.
                //        Cela inciterait aussi au passage à la PersistentCollection (son constructeur ayant besoin d'un ClassMetadataInfo,
                //        or pour dédoublonner ici on en aurait aussi besoin).
            }
            $r = new ManagedCollection($em, $rt);
        }
        return $r;
    }

    public function loadRelated($liste, $rels): ManagedCollection
    {
        return static::loadRelatedFor($liste, $rels, $this->em, true);
    }
}
