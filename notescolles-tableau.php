<?php
// Sécurité : ne doit pas être exécuté s'il n'est pas lancé par un script autorisé
if ( !defined('OK') )  exit();

// Script d'affichage du tableau des notes de colles, spécifique aux professeurs associés à la matière
// Script lancé par notescolles.php
// Autorisation obligatoirement égale à 5
// Variables $matiere, $mid et $cle déjà réglées

// Récupération des colleurs
$resultat = $mysqli->query("SELECT c.id, IF(LENGTH(nom),CONCAT(prenom,' ',nom),login) AS nom
                            FROM heurescolles LEFT JOIN utilisateurs AS c ON colleur = c.id 
                            WHERE matiere = $mid GROUP BY c.id ORDER BY nom, prenom, login");
$select_colleurs = '';
while ( $r = $resultat->fetch_assoc() )
  $select_colleurs .= "\n      <option value=\"${r['id']}\">${r['nom']}</option>";
$resultat->free();

// Récupération des semaines
$resultat = $mysqli->query('SELECT id, DATE_FORMAT(debut,\'%w%Y%m%e\') AS debut, DATE_FORMAT(ADDDATE(debut,7-DAYOFWEEK(debut)),\'%w%Y%m%e\') AS fin FROM semaines WHERE colle = 1 ORDER BY id');
if ( $resultat->num_rows )  {
  $semaines_debut = $semaines_fin = '';
  while ( $r = $resultat->fetch_assoc() )  {
    $semaines_debut .= "\n        <option value=\"${r['id']}\">".format_date($r['debut']).'</option>';
    $semaines_fin .= "\n        <option value=\"${r['id']}\">".format_date($r['fin']).'</option>';
    $nmax = $r['id'];
  }
  $resultat->free();
  if ( !isset($_REQUEST['ndebut']) || !ctype_digit($ndebut = $_REQUEST['ndebut']) || ( $ndebut < 1 ) || ( $ndebut > $nmax ) )
    $ndebut = 1;
  if ( !isset($_REQUEST['nfin']) || !ctype_digit($nfin = $_REQUEST['nfin']) || ( $nfin < $ndebut ) || ( $nfin > $nmax ) )  {
    // Recherche de la semaine actuelle
    $resultat = $mysqli->query('SELECT id, DATE_FORMAT(debut,\'%d/%m/%Y\') FROM semaines WHERE colle = 1 AND debut<CURDATE() ORDER BY debut DESC LIMIT 1');
    if ( $resultat->num_rows )  {
      $nfin = $resultat->fetch_row()[0];
      $resultat->free();
    }
    else
      $nfin = 0;
    if ( $nfin < $ndebut )
      $nfin = $nmax;
  }
  $semaines_debut = str_replace("\"$ndebut\"","\"$ndebut\" selected",$semaines_debut);
  $semaines_fin = str_replace("\"$nfin\"","\"$nfin\" selected",$semaines_fin);
}
// S'il n'y a pas de semaines de colles dans le planning, la suite n'a pas de sens
else  {
  debut($mysqli,'Tableau de notes de colles','Cette page ne contient aucune information.',5,' ');
  $mysqli->close();
  fin();
}

// Élèves actifs
if ( isset($_REQUEST['elevesactifs']) && $_REQUEST['elevesactifs'] )  {
  $elevesactifs = 'AND mdp > \'0\'';
  $mentionnom = '';
  $ea = 1;
  $select_ea = "\n        <option value=\"0\">Voir tous les élèves</option>\n        <option value=\"1\" selected>Voir seulement les élèves actifs</option>";
}
else  {
  $elevesactifs = '';
  $mentionnom = ',IF(mdp > \'0\', \'\', \' (compte désactivé)\')';
  $ea = 0;
  $select_ea = "\n        <option value=\"0 selected\">Voir tous les élèves</option>\n        <option value=\"1\">Voir seulement les élèves actifs</option>";
}

//////////////////////////////////
// Exportation des notes en xls //
//////////////////////////////////
if ( isset($_REQUEST['xls']) && !headers_sent() )  {
  
  // Recherche des notes concernées
  // Le séparateur décimal doit rester le point pour que le logiciel de lecture du xls comprenne
  $resultat = $mysqli->query("SELECT nom, REPLACE(GROUP_CONCAT( IFNULL(note,'') ORDER BY sid SEPARATOR '|'),',','.') AS notes, LEFT(AVG(IF(note='abs' OR note='nn',NULL,REPLACE(note,',','.'))),4) AS moyenne
                              FROM ( SELECT s.id AS sid, u.id AS eid, IF(LENGTH(nom),CONCAT(nom,' ',prenom),CONCAT(login,' (identifiant)')) AS nom
                                     FROM semaines AS s LEFT JOIN utilisateurs AS u ON 1 
                                     WHERE colle = 1 AND s.id >= $ndebut AND s.id <= $nfin AND u.autorisation=2 $elevesactifs AND FIND_IN_SET($mid,u.matieres) ORDER BY nom ) AS t
                              LEFT JOIN notescolles ON sid = semaine AND eid = eleve AND matiere = $mid GROUP BY eid ORDER BY nom,sid");
  if ( $resultat->num_rows )  {
    // Fonctions de saisie
    function saisie_nombre($l, $c, $v)  {
      echo pack("sssss", 0x203, 14, $l, $c, 0).pack("d", $v);
      return;
    }
    function saisie_chaine($l, $c, $v)  {
      echo pack("ssssss", 0x204, 8 + strlen($v), $l, $c, 0, strlen($v)).$v;
      return;
    }
    // Envoi des headers
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=notes.xls");
    header("Content-Transfer-Encoding: binary");
    // Début du fichier xls
    echo pack("sssss", 0x809, 6, 0, 0x10, 0);
    // Remplissage
    $i = 0;
    $semaines = $mysqli->query("SELECT DATE_FORMAT(debut,'%d/%m') FROM semaines WHERE colle = 1 AND id >= $ndebut AND id <= $nfin ORDER BY id");
    while ( $r = $semaines->fetch_row() )
      saisie_chaine(0, ++$i, $r[0]);
    $semaines->free();
    saisie_chaine(0, $colmoy = ++$i, 'Moyenne');
    $i = 0;
    while ( $r = $resultat->fetch_assoc() )  {
      saisie_chaine(++$i, 0, utf8_decode($r['nom']));
      $notes = explode('|',$r['notes']);
      foreach ( $notes as $j => $n)
        if ( is_numeric($n) )
          saisie_nombre($i, $j+1, $n);
        elseif ( strlen($n) )
          saisie_chaine($i, $j+1, $n);
      saisie_nombre($i, $colmoy, $r['moyenne']);
    }
    // Fin du fichier xls
    echo pack("ss", 0x0A, 0x00);
    $resultat->free();
    $mysqli->close();
    exit();
  }
  // Si ça n'a pas marché : message
  $message = 'Il n\'y a pas de tableau à générer. Réessayez en changeant les semaines visibles ou après avoir saisi des notes de colles.';
  
}

////////////
/// HTML ///
////////////
debut($mysqli,"Tableau des notes de colles - ${matiere['nom']}",$message,5,"notescolles?$cle&tableau",array('action'=>'notescolles','matiere'=>$mid,'css'=>'datetimepicker'));

// Vérification de la présence de semaines de préparation à l'oral
$resultat = $mysqli->query('SELECT * FROM semaines WHERE colle = 2');
if ( $resultat->num_rows )  {
  $prepaoral = "  <p>Les semaines de préparation à l'oral ne peuvent pas apparaître dans ce tableau, puisque plusieurs notes peuvent être mises pour un seul élève la même semaine.</p>\n";
  $resultat->free();
}
else 
  $prepaoral = '';

// Icônes d'action générales et barre de sélection
echo <<<FIN

  <article>
    <input onclick="location.href='?$cle'" type="button" class="ligne" value="Déclaration des heures de colles">
    <input onclick="location.href='?$cle&amp;gestion'" type="button" class="ligne" value="Statistiques, liste des colles, réglages">
    <input onclick="location.href='?$cle&amp;tableau'" type="button" class="ligne" value="Tableau de notes téléchargeable" disabled>
  </article>

  <article>
    <p class="ligne">
      <label for="sdebut">Début&nbsp;:</label>
      <select id="sdebut" onchange="window.location.href='?$cle&amp;tableau&amp;ndebut='+this.value+'&amp;nfin=$nfin&amp;elevesactifs=$ea'">$semaines_debut
      </select>
    </p>
    <p class="ligne">
      <label for="sfin">Fin&nbsp;:</label>
      <select id="sfin" onchange="window.location.href='?$cle&amp;tableau&amp;ndebut=$ndebut&amp;nfin='+this.value+'&amp;elevesactifs=$ea'">$semaines_fin
      </select>
    </p>
    <p class="ligne">
      <label for="colleurs">Mise en évidence&nbsp;:</label>
      <select id="colleurs" onchange="if (this.value>0) { $('[data-colleur]').attr('class','collnosel'); $('[data-colleur=&quot;'+this.value+'&quot;]').attr('class','collsel'); } else $('[data-colleur]').removeClass();">
        <option value="0">tous les colleurs</option>$select_colleurs
      </select>
    </p>
    <p class="ligne">
      <label for="eleves">Élèves&nbsp;:</label>
      <select id="eleves" onchange="window.location.href='?$cle&amp;tableau&amp;ndebut=$ndebut&amp;nfin=$nfin&amp;elevesactifs='+this.value">$select_ea
      </select>
    </p>
    <input onclick="location.href='?$cle&amp;tableau&amp;xls&amp;ndebut=$ndebut&amp;nfin=$nfin&amp;elevesactifs=$ea'" type="button" class="ligne" value="Télécharger ce tableau au format xls">
$prepaoral  </article>

FIN;

// Recherche des notes concernées
$resultat = $mysqli->query("SELECT nom, GROUP_CONCAT( IF(ISNULL(note),'<td></td>',CONCAT('<td data-colleur=\"',colleur,'\">',note,'</td>')) ORDER BY sid SEPARATOR '') AS notes,
                            LEFT(REPLACE(AVG(IF(note='abs' OR note='nn',NULL,REPLACE(note,',','.'))),'.',','),4) AS moyenne
                            FROM ( SELECT s.id AS sid, u.id AS eid, CONCAT('<td>',IF(LENGTH(nom),CONCAT(nom,' ',prenom),login)$mentionnom,'</td>') AS nom
                                   FROM semaines AS s LEFT JOIN utilisateurs AS u ON 1 
                                   WHERE colle = 1 AND s.id >= $ndebut AND s.id <= $nfin AND u.autorisation=2 $elevesactifs AND FIND_IN_SET($mid,u.matieres) ORDER BY nom ) AS t
                            LEFT JOIN notescolles ON sid = semaine AND eid = eleve AND matiere = $mid GROUP BY eid ORDER BY nom,sid");
if ( $resultat->num_rows )  {
  echo "\n  <table>\n    <thead>\n      <tr><th></th>";
  $semaines = $mysqli->query("SELECT DATE_FORMAT(debut,'%d/%m') FROM semaines WHERE colle = 1 AND id >= $ndebut AND id <= $nfin ORDER BY id");
  $nb = $semaines->num_rows;
  while ( $r = $semaines->fetch_row() )
    echo "<th class=\"vertical\"><span>${r[0]}</span></th>";
  echo '<th class="vertical"><span>Moyenne</span></th>';
  $semaines->free();
  echo "</tr>\n    </thead>\n    <tbody>\n";
  while ( $r = $resultat->fetch_assoc() )
    if ( strlen(str_replace('<td></td>','',$r['notes'])) )
      echo "      <tr>${r['nom']}${r['notes']}<td>${r['moyenne']}</td></tr>\n";
    else
      echo "      <tr>${r['nom']}<td class=\"pasnote\" colspan=\"$nb+1\">Pas encore de note pour cet élève</td></tr>\n";
  $resultat->free();
  echo "    </tbody>\n  </table>\n\n";
}
else
  echo "\n  <article>\n    <h2>Il n'y a encore aucune note de colle en ${matiere['nom']} cette année.</h2>\n  </article>\n\n";
$mysqli->close();

fin(true);
?>
