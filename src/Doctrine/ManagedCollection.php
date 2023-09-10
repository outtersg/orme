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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;

class ManagedCollection extends ArrayCollection
{
    use RelatedLoader { loadRelated as private _loadRelated; }

    protected $em;

    public function __construct(EntityManagerInterface $em, array $elements)
    {
        parent::__construct($elements);
        $this->em = $em;
    }

    /**
     * Précharge plusieurs relations depuis les objets de la collection.
     *
     * @param string|string[] ...$relss Chacun des paramètres à la méthode est soit le nom d'une relation, soit un tableau enchaînant plusieurs relations.
     *                                  Ex.: loadRelated('couleurDeCheveux', [ 'd' => 'diplôme', 'e' => 'd.école', 'e.adresse' ], 'conjoint')
     *                                  Chaque relation ou groupe de relations sera chargée indépendamment (dans l'ex. 3 requêtes seront émises pour aller chercher couleur de cheveux, diplômes avec toute leur structure, et conjoint); un groupe de relations est à l'inverse chargé en une fois (les diplômes seront ramenés avec leur école et l'adresse de celle-ci).
     *
     * @return ArrayCollection La liste des entités remontées par le _premier_ élément du _dernier_ groupe.
     *                         (dans notre exemple: la liste des conjoints).
     */
    public function loadRelated(...$relss): ArrayCollection
    {
        foreach ($relss as $rels) {
            $r = $this->_loadRelated($this, $rels);
        }
        return $r;
    }
}
