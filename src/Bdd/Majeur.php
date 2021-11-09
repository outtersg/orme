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

class Majeur
{
	public function insérer($bdd, $table, $lignes)
	{
		switch(count($lignes))
		{
			case 0: return true;
			case 1: return $this->_insérer($bdd, $table, array_keys($lignes), $lignes);
		}
		
		$cols = array_keys($lignes[0]);
		foreach($lignes as $ligne)
			if(array_keys($ligne) !== $cols)
				return false;
		
		return $this->_insérer($bdd, $table, $cols, $lignes);
	}
	
	public function _insérer($bdd, $table, $colonnes, $lignes)
	{
		if(!method_exists($bdd, 'pgsqlCopyFromArray')) return false;
		$échappés = [ "\n" => '\n', "\r" => '\r', "\\" => "\\\\" ]; // Le \ bien à la fin.
		
		if(!($bloc = $this->tabEnBloc($lignes, true, $échappés))) return false;
		
		return $bdd->pgsqlCopyFromArray
		(
			$table,
			$bloc,
			$this->sépc,
			$this->null,
			implode(',', $colonnes)
		);
	}
	
	public function tabEnBloc($lignes, $résTableau = false, $échappés = [])
	{
		if(!count($lignes)) return $résTableau ? $lignes : '';
		
		// On tente une première mise en bloc opportuniste, avec des séparateurs a priori peu utilisés.
		
		$tentative = 0;
		$numSép = 2;
		$null = $sépc = $sépl = 0;
		$séps = [ & $null, & $sépc, & $sépl ];
		foreach($séps as & $ptrSép)
			while($ptrSép = chr(++$numSép) && isset($échappés[$numSép])) {}
		if($ptrSép >= ' ') return null; // Ouille, on est entré dans la plage ASCII lisible sans trouver de séparateurs disponibles dans les caractères de contrôle.
		
		retenter:
		$nnull = 0;
		$nsépc = 0;
		$r = [];
		foreach($lignes as $ligne)
		{
			foreach($ligne as $col => & $ptrCol)
				if($ptrCol === null)
				{
					$ptrCol = $null;
					++$nnull;
				}
				else if(is_object($ptrCol) && $ptrCol instanceof \DateTime)
					$ptrCol = $ptrCol->format('Y-m-d H:i:s');
			
			$nsépc += count($ligne) - 1;
			$r[] = implode($sépc, $ligne);
		}
		$bloc = $r;
		$bloc[] = ''; // Pour qu'implode termine par une fin de ligne.
		$bloc = implode($sépl, $bloc);
		
		// Décompte!
		// A-t-on dans notre bloc plus de séparateurs qu'attendu?
		// Cela indiquerait que notre séparateur figure dans les données (et donc ne peut pas servir de séparateur).
		
		$nCars = count_chars($bloc);
		
		$àVérifier = [ $nnull, $nsépc, count($lignes) ];
		if($résTableau) unset($àVérifier[2]); // Si le résultat est attendu comme tableau, le séparateur de fin de ligne est sans objet.
		foreach($àVérifier as $numSép => $nAttendus)
			if($nCars[ord($séps[$numSép])] != $nAttendus)
				$retape[$numSép] = & $séps[$numSép];
		if(!isset($retape)) // Cas optimiste: on peut renvoyer notre bloc.
		{
			$this->null = $null;
			$this->sépc = $sépc;
			$this->sépl = $sépl;
			// Le remplacement des séquences d'échappement étant coûteux, on ne l'applique qu'en cas de présence d'un des caractères ciblés.
			$échappésPrésents = [];
			foreach($échappés as $avant => $après)
				if(strlen($avant) > 1 || $nCars[ord($avant)] > 0)
				{
					if($après === null && (strlen($avant) == 1 || strpos($bloc, $avant) !== false)) return false; // Mode fatal: une séquence finissant en null vaut aveux immédiat d'impuissance.
					$échappésPrésents[$avant] = $après;
				}
			if(count($échappésPrésents))
				if($résTableau)
					foreach($r as & $ptrLigne)
						$ptrLigne = strtr($ptrLigne, $échappésPrésents);
				else
					$bloc = strtr($bloc, $échappésPrésents);
			// Retour!
			return $résTableau ? $r : $bloc;
		}
		
		assert(++$tentative < 2); // Gros problème si on tombe là: on est tombé sur un os, on a cru le résoudre en cherchant scrupuleusement des séparateurs vraiment pas utilisés, mais on retombe sur un nouvel os.
		
		foreach($échappés as $avant => $après) // On s'assure que les caractères à échapper ne sont pas pris comme séparateurs, en simulant leur présence dans les données.
			$nCars[ord($avant)] = 1;
		foreach($retape as & $ptrSép)
		{
			while($nCars[++$numSép])
				if($ptrSép == ' ') // Aïe, on a exploité tous les séparateurs possibles (on arrive dans la zone ASCII lisible).
					return null;
			$ptrSép = chr($numSép);
		}
		unset($retape);
		goto retenter;
	}
}

?>

