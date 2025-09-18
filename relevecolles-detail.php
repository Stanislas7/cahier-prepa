<?php
// Sécurité : ne doit pas être exécuté s'il n'est pas lancé par un script autorisé
if ( !defined('OK') )  exit();

// Script d'affichage du tableau récapitulatif total de la page de relève des colles,
// tableau visible uniquement des comptes de type lycée et des admins
// Script lancé par relevecolles.php

//////////////
//// HTML ////
//////////////
debut($mysqli,'Relève des déclarations de colles - Détail',$message,$autorisation,'relevecolles',array('action'=>'dureecolles','css'=>'datetimepicker'));
echo <<<FIN
  
  <article>
    <input onclick="location.href='relevecolles'" type="button" class="ligne" value="Relève des déclarations de colles">
    <input onclick="location.href='?stats'" type="button" class="ligne" value="Statistiques par matière et par colleur">
    <input onclick="location.href='?detail'" type="button" class="ligne" value="Détail de toutes les heures déclarées" disabled>
  </article>

FIN;

// Récupération de l'ensemble des élèves
$resultat = $mysqli->query('SELECT e.id, IF(LENGTH(nom),CONCAT(nom," ",prenom),login) AS nomcomplet, IF(LENGTH(nom),CONCAT(LEFT(prenom,1),". ",nom),login) AS initiale
                            FROM notescolles AS n LEFT JOIN utilisateurs AS e ON eleve = e.id
                            GROUP BY e.id ORDER BY nomcomplet');
$eleves = array();
$select_eleves = '';
while ( $r = $resultat->fetch_assoc() )  {
  $eleves[$r['id']] = $r;
  $select_eleves .= "\n        <option value=\"${r['initiale']}\">${r['nomcomplet']}</option>";
}
$resultat->free();

// Récupération de l'ensemble des colleurs participants (professeurs compris)
$resultat = $mysqli->query('SELECT c.id, IF(LENGTH(nom),CONCAT(nom," ",prenom),login) AS nomcomplet, IF(LENGTH(nom),CONCAT(LEFT(prenom,1),". ",nom),login) AS initiale
                            FROM heurescolles AS h LEFT JOIN utilisateurs AS c ON colleur = c.id
                            GROUP BY c.id ORDER BY nomcomplet');
$colleurs = array();
$select_colleurs = '';
while ( $r = $resultat->fetch_assoc() )  {
  $colleurs[$r['id']] = $r['initiale'];
  $select_colleurs .= "\n      <option value=\"${r['initiale']}\">${r['nomcomplet']}</option>";
}
$resultat->free();

// Récupération des matières concernées
$resultat = $mysqli->query('SELECT m.id, nom FROM heurescolles LEFT JOIN matieres AS m ON matiere = m.id GROUP BY m.id ORDER BY ordre');
$select_matieres = '';
while ( $r = $resultat->fetch_assoc() )
  $select_matieres .= "\n      <option value=\"${r['nom']}\">${r['nom']}</option>";
$resultat->free();

// Listes des colles déclarées
$resultat = $mysqli->query("SELECT h.id, h.colleur, m.nom AS matiere, DATE_FORMAT(jour,'%d/%m/%y') AS jour, 
                            IF(rattrapage,DATE_FORMAT(rattrapage,'%d/%m/%y'),'-') AS rattrapage, duree, original,
                            IF(LENGTH(description),description,CONCAT('|',GROUP_CONCAT(n.eleve))) AS description, 
                            IF(releve,DATE_FORMAT(releve,'%d/%m'),'-') AS releve
                            FROM heurescolles AS h LEFT JOIN matieres AS m ON h.matiere = m.id
                                 LEFT JOIN utilisateurs AS c ON h.colleur = c.id LEFT JOIN notescolles AS n ON heure = h.id
                            GROUP BY h.id
                            ORDER BY h.jour DESC, m.ordre, c.nom");
$mysqli-> close();
// Affichage
if ( $n = $resultat->num_rows )  {
  echo  <<<FIN
  <article>
    <h3>Liste détaillée de toutes les colles déclarées</h3>
    <p class="ligne">
      <label for="matieres">Afficher une matière&nbsp;:</label>
      <select id="matieres" onchange="$('#notes tr').show(); $('select').each( function() { if ( this.value != '0' ) $('#notes tr').has('td').not(':contains(&quot;'+this.value+'&quot;)').hide(); });">
        <option value="0">toutes les matières</option>$select_matieres
      </select>
    </p>
    <p class="ligne">
      <label for="colleurs">Afficher un colleur&nbsp;:</label>
      <select id="matieres" onchange="$('#notes tr').show(); $('select').each( function() { if ( this.value != '0' ) $('#notes tr').has('td').not(':contains(&quot;'+this.value+'&quot;)').hide(); });">
        <option value="0">tous les colleurs</option>$select_colleurs
      </select>
    </p>
    <p class="ligne">
      <label for="eleves">Afficher un élève&nbsp;:</label>
      <select id="matieres" onchange="$('#notes tr').show(); $('select').each( function() { if ( this.value != '0' ) $('#notes tr').has('td').not(':contains(&quot;'+this.value+'&quot;)').hide(); });">
        <option value="0">tous les élèves</option>$select_eleves
      </select>
    </p>
    <table id="notes">
      <tbody>
        <tr><th>Colleur</th><th>Matière</th><th>Jour</th><th>Rattrapage</th><th>Élèves (notes) ou Description</th><th>Durée</th><th>Relève</th></tr>

FIN;
  // Affichage de chaque heure
  while ( $r = $resultat->fetch_assoc() )  {
    $duree = format_duree($r['duree']);
    $original = '';
    if ( ( $autorisation == 4 ) && ( strlen($r['releve']) == 1 ) )
      $duree = "<span data-id=\"${r['id']}\" class=\"editable duree\" data-champ=\"duree\">$duree</span>";
    if ( $r['description'][0] != '|' )
      $texte = $r['description'];
    else  {
      // Liste des élèves notés
      $texte = array();
      foreach ( explode(',',substr($r['description'],1)) as $id )
        $texte[$eleves[$id]['nomcomplet']] = '<span>'.$eleves[$id]['initiale'].'</span>';
      ksort($texte);
      $texte = implode(', ',$texte);
      // Mise en évidence des colles dont la durée originale a été modifiée
      if ( $r['original'] != $r['duree'] )
        $original = ' class="nooriginal" title="Valeur originale : '.format_duree($r['original']).'"';
    }
    // Affichage
    echo "        <tr>\n          <td>{$colleurs[$r['colleur']]}</td><td>${r['matiere']}</td><td>${r['jour']}</td><td>${r['rattrapage']}</td><td>$texte</td><td$original>$duree</td><td>${r['releve']}</td>\n        </tr>\n";
  }
  $resultat->free();
  echo <<<FIN
      </tbody>
    </table>
  </article>

FIN;
}

fin(true,false,'datetimepicker');
?>
