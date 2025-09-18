<?php
// Sécurité : ne doit pas être exécuté s'il n'est pas lancé par un script autorisé
if ( !defined('OK') )  exit();

// Script d'affichage des notes de colles, spécifique aux élèves
// Script lancé par notescolles.php
// Autorisation obligatoirement égale à 2, sauf si mode lecture

// Mode lecture
// On vient de notescolles.php. On a déjà cherché une matière que l'on peut 
// avoir ($matiere,$mid et $cle).
if ( $mode_lecture )  {
  $mid ??= 0;
  // Récupération des élèves pour la sélection
  $resultat = $mysqli->query("SELECT id, CONCAT(nom,' ',prenom) AS eleve FROM utilisateurs WHERE autorisation = 2 AND mdp > '0' AND FIND_IN_SET($mid,matieres) ORDER BY IF(LENGTH(nom),nom,login)");
  if ( $resultat->num_rows )  {
    $eid = 0;
    $select = '';
    while ( $r = $resultat->fetch_row() )  {
      $select .= "      <option value=\"${r[0]}\">${r[1]}</option>\n";
      if ( $r[0] == ( $_REQUEST['eid'] ?? 0 ) )
        $eid = $r[0];
    }
    // Par défaut : premier élève
    if ( !$eid )  {
      $resultat->data_seek(0);
      $eid = $resultat->fetch_row()[0]; 
    }
    $resultat->free();
    // Affichage
    $select = str_replace("\"$eid\"","\"$eid\" selected",$select);
    $icones .= "\n  <p id=\"selecteleve\" class=\"topbarre\"><span>Voir ce que voit l'élève</span>\n    <select>\n$select\n    </select>\n  </p>\n";  
  }
  else  {
    debut($mysqli,'Notes de colles',$message,$autorisation,'notescolles',$donnees);
    echo "$icones\n  <article><h2>Il n'y a aucun élève disponible.</h2></article>\n\n";
    fin(true);
  }
}
else
  $eid = $_SESSION['id'];

////////////////////////////////////////////////
// Pas de matière demandée : toutes les notes //
////////////////////////////////////////////////
if ( empty($_GET) )  {
  $resultat = $mysqli->query("SELECT m.id, cle, nom, LEFT(REPLACE(AVG(IF(note='abs' OR note='nn',NULL,REPLACE(note,',','.'))),'.',','),4) AS moy
                              FROM notescolles LEFT JOIN matieres AS m ON matiere = m.id WHERE eleve = $eid GROUP BY matiere ORDER BY m.ordre");
  if ( $n = $resultat->num_rows )  {
    // Si une seule matière trouvée, réglage automatique sur cette matière
    if ( $n == 1 )  {
      $matiere = $resultat->fetch_assoc();
      $resultat->free();
    }
    // Si plusieurs matières trouvées, affichage global
    else  {
      debut($mysqli,'Notes de colles',$message,$autorisation,'notescolles',$donnes ?? false);
      // Affichage de la moyenne globale
      $resultat2 = $mysqli->query("SELECT LEFT(REPLACE(AVG(IF(note='abs' OR note='nn',NULL,REPLACE(note,',','.'))),'.',','),4) AS moy FROM notescolles WHERE eleve = $eid");
      $moyenne = $resultat2->fetch_row()[0];
      $resultat2->free();
      echo "$icones\n  <article>\n    <h3>Moyenne globale : $moyenne/20</h3>\n    <h3>Moyennes par matière :</h3>";
      // Affichage des moyennes
      while ( $r = $resultat->fetch_assoc() )
        echo "\n    <h4 class=\"moyenne\"><a href=\"notescolles?${r['cle']}\">${r['nom']}</a> : ${r['moy']}/20</h4>";
      $resultat->free();
      echo "\n  </article>\n  <article>\n    <table>\n      <tr><th>Date</th><th>Matière</th><th>Colleur</th><th>Note</th></tr>";
      // Récupération de l'ensemble des notes, semaines, colleurs propres à l'élève
      $resultat = $mysqli->query("SELECT DATE_FORMAT(jour,'%w%Y%m%e') AS jour, m.nom as matiere, IF(LENGTH(c.nom),c.nom,c.login) AS colleur, n.note
                                  FROM notescolles AS n LEFT JOIN matieres AS m ON n.matiere = m.id LEFT JOIN utilisateurs AS c ON n.colleur=c.id LEFT JOIN heurescolles AS h ON n.heure = h.id
                                  WHERE n.eleve = $eid ORDER BY h.jour DESC");
      $mysqli->close();
      // Affichage des notes concernées
      while ( $r = $resultat->fetch_assoc() )
        echo "\n      <tr><td>".ucfirst(format_date($r['jour']))."</td><td>${r['matiere']}</td><td>${r['colleur']}</td><td>${r['note']}</td></tr>";
      $resultat->free();
      echo "\n    </table>\n  </article>\n";
      fin($editionjs ?? false);
    }
  }
  // Pas de matière concernée !
  else  {
    debut($mysqli,'Notes de colles','Cette page ne contient aucune information.',$autorisation,' ',$donnes ?? false);
    echo $icones;
    $mysqli->close();
    fin($editionjs ?? false);
  }
}

////////////////////////////////////////
// Validation de la requête : matière //
////////////////////////////////////////

// Recherche de la matière concernée, variable $matiere
// Si le compte n'est associé qu'à une matière, on la choisit automatiquement.
// Sinon, on cherche $_REQUEST['cle'] dans les matières disponibles.
// notes=0 : pas de note saisie
// notes=1 : déjà des notes saisies
// notes=2 : fonction désactivée, pas d'affichage
// Remarque : impossible d'y tomber en mode lecture
if ( !isset($matiere) )  {
  $resultat = $mysqli->query("SELECT id, cle, nom FROM matieres WHERE FIND_IN_SET(id,'${_SESSION['matieres']}') AND notescolles < 2");
  if ( $n = $resultat->num_rows )  {
    if ( $n == 1 )  {
      $matiere = $resultat->fetch_assoc();
      $resultat->free();
    }
    else  {
      while ( $r = $resultat->fetch_assoc() )
        if ( isset($_REQUEST[$r['cle']]) )  {
          $matiere = $r;
          break;
        }
      $resultat->free();
      // Si aucune matière trouvée
      if ( !isset($matiere) )  {
        debut($mysqli,'Notes de colles','Mauvais paramètre d\'accès à cette page.',2,' ');
        $mysqli->close();
        fin();
      }
    }
  }
  // Si aucune matière présentant son programme de colles n'est enregistrée
  else  {
    debut($mysqli,'Notes de colles','Cette page ne contient aucune information.',2,' ');
    $mysqli->close();
    fin();
  }
}
        
////////////
/// HTML ///
////////////
debut($mysqli,"Notes de colles - ${matiere['nom']}",$message,$autorisation,"notescolles?${matiere['cle']}",$donnees ?? false);

// Récupération de la moyenne
$resultat = $mysqli->query("SELECT LEFT(REPLACE(AVG(IF(note='abs' OR note='nn',NULL,REPLACE(note,',','.'))),'.',','),4) AS moy FROM notescolles WHERE eleve = $eid AND matiere = ${matiere['id']}");
$moyenne = $resultat->fetch_row()[0];
$resultat->free();

// Récupération de l'ensemble des notes, semaines, colleurs propres à l'élève
$resultat = $mysqli->query("SELECT DATE_FORMAT(jour,'%w%Y%m%e') AS jour, n.note, IF(LENGTH(c.nom),c.nom,c.login) AS colleur
                            FROM notescolles AS n LEFT JOIN utilisateurs AS c ON n.colleur=c.id LEFT JOIN heurescolles AS h ON n.heure = h.id
                            WHERE n.eleve = $eid AND n.matiere = ${matiere['id']} ORDER BY h.jour DESC");
$mysqli->close();
if ( $resultat->num_rows )  {
  // Affichage de la moyenne
  echo "$icones\n  <article>\n    <h3>Moyenne : $moyenne/20</h3>\n  </article>\n";
  // Affichage des notes concernées
  echo "\n  <article>\n    <table>\n      <tr><th>Date</th><th>Colleur</th><th>Note</th></tr>";
  while ( $r = $resultat->fetch_assoc() )
    echo "\n      <tr><td>".ucfirst(format_date($r['jour']))."</td><td>${r['colleur']}</td><td>${r['note']}</td></tr>";
  $resultat->free();
  echo "\n    </table>\n  </article>\n";
}
else
  echo "$icones\n  <article>\n    <h2>Vous n'avez encore aucune note en ${matiere['nom']} cette année.</h2>\n  </article>\n";

fin($editionjs ?? false);
?>
