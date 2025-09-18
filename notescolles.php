<?php
// Sécurité
define('OK',1);
// Configuration
include('config.php');
// Fonctions
include('fonctions.php');

// Mode lecture
$admin = $autorisation && $_SESSION['admin'];
$mode_lecture = 0;
$icones = '';
// Mise en place des icônes générales
// Valable pour les deux premières parties (descriptif matières ou erreurs)
// et notescolles-eleves.php en mode lecture
if ( ( $autorisation == 5 ) || $admin )  {
  $donnees = array('action'=>'notescolles','matiere'=>0,'protection'=>0,'edition'=>0);
  $mode_lecture = $_SESSION['mode_lecture'];
  $icones = "\n  <div id=\"icones\">\n    <a class=\"icon-lecture".($mode_lecture ? ' mev' : '')."\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n";
  $editionjs = true;
}

//////////////////
// Autorisation //
//////////////////

// Accès aux professeurs, colleurs, élèves connectés uniquement
// Les comptes de type lycée doivent aller sur une autre page
$mysqli = connectsql();
// Pas d'accès sans connexion
if ( !$autorisation )  {
  $titre = 'Notes de colles';
  $actuel = false;
  include('login.php');
}

// Affichage différent pour les élèves
// notescolles-eleves.php contient fin()
if ( $autorisation == 2 )
  include('notescolles-eleves.php');
// Accès interdit pour les non colleurs/non professeurs
if ( ( $autorisation != 3 ) && ( $autorisation != 5 ) && !$mode_lecture )  {
  debut($mysqli,'Notes de colles','Vous n\'avez pas accès à cette page.',$autorisation,' ');
  $mysqli->close();
  fin();
}

// Fonction pour l'affichage des durées en heures/minutes. Argument en minutes.
function format_duree($duree)  {
  if ( $duree == 0 )
    return '-';
  if ( $duree >= 60 )
    return intdiv($duree,60).'h'.( $duree%60 ?: '');
  return ($duree%60).'m';
}

/////////////////////////////////////////////////
// Pas de matière demandée : affichage spécial //
/////////////////////////////////////////////////
// Affichage de la liste des matières si plusieurs
// possibles, des notes de la matière si une seule
// Ici, on est forcément colleur, prof ou admin (en mode lecture)
if ( empty($_GET) )  {
  $resultat = $mysqli->query("SELECT m.id, cle, nom, notescolles, dureecolles, heurescolles, COUNT(n.id) AS n
                              FROM matieres AS m LEFT JOIN notescolles AS n ON m.id = n.matiere AND colleur = ${_SESSION['id']}
                              WHERE FIND_IN_SET(m.id,'".str_replace('c','',$_SESSION['matieres']).'\') AND notescolles < 2
                              GROUP BY m.id ORDER BY ordre');
  if ( $n = $resultat->num_rows )  {
    // Si une seule matière trouvée, réglage automatique sur cette matière et on sort
    if ( $n == 1 )  {
      $matiere = $resultat->fetch_assoc();
      $resultat->free();
    }
    // Si plusieurs matières trouvées, choix à faire.
    else  {
      // notescolles-eleves.php contient fin()
      if ( $mode_lecture == 3 )
        include('notescolles-eleves.php');
      debut($mysqli,'Notes de colles',$message,$autorisation,'notescolles',$donnees ?? false);
      echo "$icones\n  <article>\n    <h2>Mes matières</h2>";
      $matsansnoteglobal = $matsansnoteperso = 0;
      while ( $r = $resultat->fetch_assoc() )  {
        if ( $mode_lecture )
          echo "\n    <h3 class=\"detailmatiere\"><a href=\"notescolles?${r['cle']}\">${r['nom']}</a></h3>";
        elseif ( $r['notescolles'] )  {
          switch ( $r['n'] )  {
            case 0 : $notes = '(Aucune note déjà mise)'; $matsansnoteperso += 1; break;
            case 1 : $notes = '(Une seule note déjà mise)'; break;
            default : $notes = "(${r['n']} notes déjà mises)";
          }
          echo "\n    <h3 class=\"detailmatiere\"><a href=\"notescolles?${r['cle']}\">${r['nom']}</a><span>$notes</span></h3>";
        }
        else  {
          echo "\n    <h3 class=\"detailmatiere\"><a href=\"notescolles?${r['cle']}\">${r['nom']}</a><span>(Aucune note pour l'instant)</span></h3>";
          // Alerte affichée seulement pour les profs, et s'ils sont bien notés profs pour cette matière
          if ( ( $autorisation == 5 ) && in_array($r['id'],explode(',',$_SESSION['matieres'])) )
            $matsansnoteglobal += 1;
        }
      }
      $resultat->free();
      if ( $matsansnoteglobal )
        echo "\n    <p>".( ( $matsansnoteglobal == 1 ) ? 'Une matière' : "$matsansnoteglobal matières" ).' ne contient pas encore de notes de colles du tout. Vous pouvez désactiver la fonction «&nbsp;notes de colles&nbsp;» pour les matières où vous ne comptez pas l\'utiliser, dans les <a href="matieres">réglages de vos matières</a>. Cela évite les affichages non nécessaires.</p>';
      if ( $matsansnoteperso )
        echo "\n    <p>".( ( $matsansnoteperso == 1 ) ? 'Une matière' : "$matsansnoteperso matières" ).' ne contient pas encore de notes de colles de votre part. '.(
          ( $admin ) ? 'Vous pouvez supprimer votre association aux matières où vous n\'intervenez pas en allant dans les <a href="utilisateurs-matieres">réglages des associations utilisateurs-matières</a>.'
                     : 'Vous pouvez demander à un professeur ayant les droits d\'administration du Cahier de supprimer votre association aux matières où vous n\'intervenez pas.'
          ).' Cela évite les affichages non nécessaires.</p>';
      echo "\n  </article>\n";
      
      // Récupération de l'ensemble des élèves associés à la matière
      $resultat = $mysqli->query("SELECT id, IF(LENGTH(nom),CONCAT(nom,' ',prenom),login) AS nomcomplet,
                                  IF(LENGTH(nom),CONCAT(LEFT(prenom,1),'. ',nom),login) AS initiale
                                  FROM utilisateurs WHERE autorisation = 2");
      $eleves = array();
      while ( $r = $resultat->fetch_assoc() )
        $eleves[$r['id']] = $r;
      $resultat->free();
      // Listes des colles déclarées
      $resultat = $mysqli->query("SELECT h.id, DATE_FORMAT(jour,'%d/%m/%y') AS jour, IF(rattrapage,DATE_FORMAT(rattrapage,'%d/%m/%y'),'-') AS rattrapage, 
                                  duree, description, IF(releve,DATE_FORMAT(releve,'%d/%m'),'-') AS releve, original, m.nom
                                  FROM heurescolles AS h JOIN matieres AS m ON matiere = m.id WHERE colleur = ${_SESSION['id']} 
                                  ORDER BY jour DESC LIMIT 20");
      // Affichage
      if ( $n = $resultat->num_rows )  {
        echo  <<<FIN

  <article>
    <h3>Liste de mes dernières colles</h3>
    <table id="notes">
      <tbody>
        <tr><th>Matière</th><th>Date</th><th>Rattrapage</th><th>Élèves (notes) ou Description</th><th>Durée</th><th>Relève</th></tr>

FIN;
        // Affichage de chaque heure
        while ( $r = $resultat->fetch_assoc() )  {
          $duree = format_duree($r['duree']);
          $data = $original = '';
          $texte = $r['description'];
          // Cas des colles classiques
          if ( !$texte )  {
            $resultat1 = $mysqli->query("SELECT eleve, note FROM notescolles WHERE heure = ${r['id']}");
            $texte = array();
            while ( $r1 = $resultat1->fetch_assoc() )  {
              $eleve = $eleves[$r1['eleve']];
              $texte[$eleve['nomcomplet']] = "<span>{$eleve['initiale']} (${r1['note']})</span>";
            }
            $resultat1->free();
            // Mise en évidence des colles dont la durée originale a été modifiée
            if ( $r['original'] != $r['duree'] )
              $original = ' class="nooriginal" title="Valeur originale : '.format_duree($r['original']).'"';
            // Tri alphabétique pour l'affichage
            ksort($texte);
            $texte = implode(', ',$texte);
          }
          // Affichage
          echo "        <tr><td>${r['nom']}</td><td>${r['jour']}</td><td>${r['rattrapage']}</td><td>$texte</td><td$original>$duree</td><td>${r['releve']}</td></tr>\n";
        }
        $resultat->free();
        echo <<<FIN
      </tbody>
    </table>
  </article>

FIN;
      }
      $mysqli->close();
      fin($editionjs ?? false);
    }
  }
  // Pas de matière concernée !
  else  {
    debut($mysqli,'Notes de colles','Cette page ne contient aucune information.',$autorisation,' ',$donnees ?? false);
    $mysqli->close();
    echo $icones;
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
if ( !isset($matiere) )  {
  $resultat = $mysqli->query('SELECT id, cle, nom, dureecolles, heurescolles FROM matieres
                              WHERE FIND_IN_SET(id,\''.str_replace('c','',$_SESSION['matieres']).'\') AND notescolles < 2' );
  if ( $resultat->num_rows == 1 )  {
    $matiere = $resultat->fetch_assoc();
    $resultat->free();
  }
  elseif ( $resultat->num_rows )  {
    if ( !empty($_REQUEST) )  {
      while ( $r = $resultat->fetch_assoc() )
        if ( isset($_REQUEST[$r['cle']]) )  {
          $matiere = $r;
          break;
        }
    }
    $resultat->free();
    // Si aucune matière trouvée
    if ( !isset($matiere) )  {
      debut($mysqli,'Notes de colles','Mauvais paramètre d\'accès à cette page.',$autorisation,' ',$donnees ?? false);
      $mysqli->close();
      echo $icones;
      fin($editionjs ?? false);
    }
  }
  // Si aucune matière avec des notes n'est enregistrée
  else  {
    debut($mysqli,'Notes de colles','Cette page ne contient aucune information.',$autorisation,' ',$donnees ?? false);
    $mysqli->close();
    echo $icones;
    fin($editionjs ?? false);
  }
}
$mid = $matiere['id'];
$cle = $matiere['cle'];
// Scripts de gestion pour les profs hors mode lecture
if ( ( $autorisation == 5 ) && !$mode_lecture )  {
  // Profs étant enregistrés en temps que colleurs
  if ( in_array("c$mid",explode(',',$_SESSION['matieres'])) )
    $profcolleur = true;
  elseif ( isset($_REQUEST['gestion']) )
    include('notescolles-gestion.php');
  elseif ( isset($_REQUEST['tableau']) )
    include('notescolles-tableau.php');
}

// Mode lecture pour les élèves
// notescolles-eleves.php contient fin()
if ( $mode_lecture == 3 )
  include('notescolles-eleves.php');
// Mode lecture pour les utilisateurs non autorisés (tous sauf colleurs)
elseif ( $mode_lecture == 4 )
  $autorisation = 3;
elseif ( $mode_lecture )  {
  debut($mysqli,'Notes de colles',$message,$autorisation,"notescolles?$cle",$donnees);
  $mysqli->close();
  echo "$icones\n  <article><h2>Cette page n'est pas autorisée pour ce type d'utilisateur.</h2></article>\n\n";
  fin(true);
}

////////////
/// HTML ///
////////////
$donnees = array('action'=>'notescolles','matiere'=>$mid,'css'=>'datetimepicker');
if ( $mode_lecture )  {
  $donnees['protection'] = $donnees['edition'] = 0;
  $icones = '<a class="icon-lecture mev" title="Modifier le mode de lecture"></a>';
}
elseif ( ( $autorisation == 3 ) || isset($profcolleur) )
    $icones = '<a class="icon-ajoute formulaire" title="Ajouter des notes de colles"></a> <a class="icon-aide" title="Aide pour les modifications des notes de colles"></a>';
elseif ( $autorisation == 5 )
    $icones = '<a class="icon-ajoute formulaire" title="Ajouter des notes de colles"></a> <a class="icon-lecture" title="Modifier le mode de lecture"></a> <a class="icon-aide" title="Aide pour les modifications des notes de colles"></a>';
debut($mysqli,"Notes de colles - ${matiere['nom']}",$message,$autorisation,"notescolles?$cle",$donnees);

// Icônes globales
echo "\n  <div id=\"icones\">\n    $icones\n</div>\n\n";

// Récupération du décompte personnel
$resultat = $mysqli->query("SELECT SUM(duree*(releve>0)) AS total_rel, SUM(duree*(releve>0)*(description>'')) AS td_rel,
                                   SUM(duree*(releve=0)) AS total_nrel, SUM(duree*(releve=0)*(description>'')) AS td_nrel
                            FROM heurescolles WHERE colleur = ${_SESSION['id']} AND matiere = $mid");
$r = $resultat->fetch_assoc();
$resultat->free();
$ligne_heures_rel = ( $r['td_rel'] ? '<p><strong>Nombre d\'heures relevées (dont séances sans note)</strong>&nbsp;:&nbsp;'.format_duree($r['total_rel']).'&nbsp;('.format_duree($r['td_rel']).')</p>'
                                   : '<p><strong>Nombre d\'heures relevées</strong>&nbsp;:&nbsp;'.format_duree($r['total_rel']).'</p>' );
$ligne_heures_nrel = ( $r['td_nrel'] ? '<p><strong>Nombre d\'heures non relevées (dont séances sans note)</strong>&nbsp;:&nbsp;'.format_duree($r['total_nrel']).'&nbsp;('.format_duree($r['td_nrel']).')</p>'
                                     : '<p><strong>Nombre d\'heures non relevées</strong>&nbsp;:&nbsp;'.format_duree($r['total_nrel']).'</p>' );
// Récupération de la moyenne personnelle
$resultat = $mysqli->query("SELECT COUNT(*) AS nb, LEFT(REPLACE(AVG(IF(note='abs' OR note='nn',NULL,REPLACE(note,',','.'))),'.',','),4) AS moy FROM notescolles WHERE colleur = ${_SESSION['id']} AND matiere = $mid");
$s = $resultat->fetch_assoc();
$resultat->free();
if ( is_null($s['moy']) )
  $moyenne = '';
else  {
  $moyenne = "\n    <p><strong>Moyenne</strong>&nbsp;:&nbsp;${s['moy']}/20</p>";
  // Récupération de la moyenne de tous les colleurs
  $resultat = $mysqli->query("SELECT COUNT(*) AS nb, LEFT(REPLACE(AVG(IF(note='abs' OR note='nn',NULL,REPLACE(note,',','.'))),'.',','),4) AS moy FROM notescolles WHERE matiere = $mid");
  $r = $resultat->fetch_assoc();
  $resultat->free();
  $moyenne .= ( is_null($r['moy']) ? '' : "\n    <p><strong>Moyenne de tous les colleurs</strong>&nbsp;:&nbsp;${r['moy']}/20 sur ${r['nb']} notes</p>" );
}

// Affichage en haut de page
if ( $autorisation == 5 )  {
  // Profs étant enregistrés en temps que colleurs : changement d'autorisation
  if ( isset($profcolleur) )
    $autorisation = 3;
  else 
    echo <<<FIN
  
  <article>
    <input onclick="location.href='?$cle'" type="button" class="ligne" value="Déclaration des heures de colles" disabled>
    <input onclick="location.href='?$cle&amp;gestion'" type="button" class="ligne" value="Statistiques, liste des colles, réglages">
    <input onclick="location.href='?$cle&amp;tableau'" type="button" class="ligne" value="Tableau de notes téléchargeable">
  </article>
  
FIN;
}
echo <<<FIN
  
  <article>
    <h3>Récapitulatif personnel</h3>
    <p><strong>Nombre de notes saisies</strong>&nbsp;:&nbsp;${s['nb']}</p>
    $ligne_heures_rel
    $ligne_heures_nrel $moyenne
  </article>

FIN;

// Récupération de l'ensemble des élèves associés à la matière
$resultat = $mysqli->query("SELECT id, IF(LENGTH(nom),CONCAT(nom,' ',prenom),login) AS nomcomplet,
                            IF(LENGTH(nom),CONCAT(LEFT(prenom,1),'. ',nom),login) AS initiale, IF(mdp > '0', 1, 0) AS actif
                            FROM utilisateurs WHERE autorisation = 2 AND FIND_IN_SET($mid,matieres) ORDER BY IF(LENGTH(nom),nom,login)");
$eleves = array();
while ( $r = $resultat->fetch_assoc() )
  $eleves[$r['id']] = $r;
$resultat->free();

// Listes des colles déclarées
$resultat = $mysqli->query("SELECT id, DATE_FORMAT(jour,'%d/%m/%y') AS jour, IF(rattrapage,DATE_FORMAT(rattrapage,'%d/%m/%y'),'-') AS rattrapage, 
                            duree, description, IF(releve,DATE_FORMAT(releve,'%d/%m'),'-') AS releve, original
                            FROM heurescolles WHERE colleur = ${_SESSION['id']} AND matiere = $mid 
                            ORDER BY heurescolles.jour DESC".( isset($_REQUEST['voirtout']) ? '' : ' LIMIT 10' ));
// Affichage
if ( $n = $resultat->num_rows )  {
  // Début de la liste
  if ( isset($_REQUEST['voirtout']) || ( $n < 10 ) )  {
    $titre = 'Liste de vos colles';
    $icone = '';
  }
  else  {
    $titre = 'Liste de vos dernières colles';
    $icone = "<a class=\"icon-voirtout\" href=\"?$cle&amp;voirtout\"></a>\n    ";
  }
  echo  <<<FIN

  <article>
    $icone<h3>$titre</h3>
    <table id="notes">
      <tbody>
        <tr><th>Date</th><th>Rattrapage</th><th>Élèves (notes) ou Description</th><th>Durée</th><th>Relève</th><th></th></tr>

FIN;
  // Affichage de chaque heure
  while ( $r = $resultat->fetch_assoc() )  {
    $duree = format_duree($r['duree']);
    $data = $original = '';
    $texte = $r['description'];
    $supprime = ( strlen($r['releve']) > 1 ) ? '<span>&nbsp;</span>' : '<a class="icon-supprime" title="Supprimer cette colle"></a>';
    $voir = $mail = '<span>&nbsp;</span>';
    // Cas des colles classiques
    if ( !$texte )  {
      $resultat1 = $mysqli->query("SELECT semaine, eleve, note, commentaire FROM notescolles WHERE heure = ${r['id']}");
      $texte = $eids = $notes = array();
      while ( $r1 = $resultat1->fetch_assoc() )  {
        $eids[] = $e = $r1['eleve'];
        $notes[] = $r1['note'];
        if ( $r1['commentaire'] )  {
          $voir = '<a class="icon-comms" title="Voir les commentaires"></a>';
          $texte[$eleves[$e]['nomcomplet']] = "<span><u>{$eleves[$e]['initiale']}</u> (${r1['note']})</span>";
        }
        else
          $texte[$eleves[$e]['nomcomplet']] = "<span>{$eleves[$e]['initiale']} (${r1['note']})</span>";
        $semaine = $r1['semaine'];
      }
      // Mise en évidence des colles dont la durée originale a été modifiée
      if ( $r['original'] != $r['duree'] )
        $original = ' class="nooriginal" title="Valeur originale : '.format_duree($r['original']).'"';
      // Tri alphabétique pour l'affichage
      ksort($texte);
      $texte = implode(', ',$texte);
      $data = ' data-eleves="'.implode('|',$eids).'" data-notes="'.implode('|',$notes)."\" data-sid=\"$semaine\"";
      $mail = '<a class="icon-mail" href="mail?enr_dests&uids='.implode(',',$eids).'" title=\"Envoyer un mail aux élèves"></a>';
    }
    // Affichage
    echo <<<FIN
        <tr>
          <td>${r['jour']}</td><td>${r['rattrapage']}</td><td>$texte</td><td$original>$duree</td><td>${r['releve']}</td>
          <td class="icones" data-id="${r['id']}">
            $voir
            $mail
            <a class="icon-edite formulaire"$data title="Éditer cette colle"></a>
            $supprime
          </td>
        </tr>

FIN;
  }
  $resultat->free();
  echo <<<FIN
      </tbody>
    </table>
  </article>

FIN;
}
else
  echo "\n  <article>\n    <h2>Vous n'avez encore saisi aucune note en ${matiere['nom']} cette année.</h2>\n  </article>\n";

// Table contenant les élèves et les groupes
$table = '';
foreach ( $eleves as $id => $eleve )
  if ( $eleve['actif'] )
    $table .= "        <tr data-id=\"$id\"><td>${eleve['nomcomplet']}</td></tr>\n";
$table .= "        <tr><td><strong>Ajouter des commentaires</strong></td><td><input type=\"checkbox\" name=\"comms\"></td></tr>\n        <tr><th colspan=\"2\">Groupes de colles</th></tr>\n";
// Récupération des groupes de colles et préparation à l'affichage
$resultat = $mysqli->query("SELECT g.id, g.nom, GROUP_CONCAT(e.id) AS eid,
                            GROUP_CONCAT( IF(LENGTH(e.nom),CONCAT(e.prenom,' ',e.nom),e.login) ORDER BY IF(LENGTH(e.nom),e.nom,e.login) SEPARATOR ', ') AS eleves
                            FROM groupes AS g LEFT JOIN utilisateurs AS e ON FIND_IN_SET(e.id,g.utilisateurs)
                            WHERE g.notes=1 AND FIND_IN_SET($mid,e.matieres) AND e.autorisation = 2
                            GROUP BY g.id ORDER BY g.nom_nat");
if ( $resultat->num_rows )  {
  while ( $r = $resultat->fetch_assoc() )
    $table .= "        <tr><td><label for=\"g${r['id']}\">Groupe ${r['nom']}&nbsp;: ${r['eleves']}</label></td><td><input type=\"checkbox\" class=\"grpnote\" name=\"g${r['id']}\" value=\"${r['eid']}\"></td></tr>\n";
  $resultat->free();
  $table .= "        <tr><td><strong>Voir tous les élèves</strong></td><td><input type=\"checkbox\"></td></tr>\n";
}

// Récupération de l'ensemble des semaines
// notesperso : élèves déjà notés par le colleur concerné
// notesautres : élèves déjà notés par les autres colleurs
// n : nombre de notes du colleur concerné
$resultat = $mysqli->query("SELECT s.id, DATE_FORMAT(debut,'%w%Y%m%e') AS debut, DATE_FORMAT(debut,'%d/%m/%Y') AS datedebut, colle, v.nom AS vacances, 
                            IFNULL(GROUP_CONCAT(IF(n.colleur=${_SESSION['id']},n.eleve,NULL)),'') AS notesperso,
                            IFNULL(GROUP_CONCAT(IF(n.colleur=${_SESSION['id']},NULL,n.eleve)),'') AS notesautres,
                            COUNT(IF(n.colleur=${_SESSION['id']},1,NULL)) AS n
                            FROM semaines AS s LEFT JOIN vacances AS v ON s.vacances = v.id
                            LEFT JOIN (SELECT * FROM notescolles WHERE matiere = $mid) AS n ON s.id = n.semaine GROUP BY s.id ORDER BY s.id");
$select_semaines = "\n        <option value=\"0\">Choisir une semaine</option>";
$notesperso = $notesautres = array();
while ( $r = $resultat->fetch_assoc() )  {
  if ( $r['colle'] == 0 )
    $select_semaines .= "\n        <option disabled data-date=\"${r['datedebut']}\">".( $r['vacances'] ?: format_date($r['debut']).' (pas de colle)' ).'</option>';
  else  {
    $select_semaines .= ( $r['colle'] == 1 )
                      ?  "\n        <option value=\"${r['id']}\" data-date=\"${r['datedebut']}\">".format_date($r['debut']).( $r['n'] ? " (${r['n']} notes déjà saisies)" : '').'</option>'
                      :  "\n        <option value=\"${r['id']}\" data-date=\"${r['datedebut']}\" data-oraux=\"1\">".format_date($r['debut']).' (préparation à l\'oral)'.( $r['n'] ? " (${r['n']} notes déjà saisies)" : '').'</option>';
    $notesperso[$r['id']] = $r['notesperso'];
    $notesautres[$r['id']] = $r['notesautres'];
  }
}
$resultat->free();

// Réglage à la semaine actuelle pour l'ajout de notes
$resultat = $mysqli->query('SELECT id, DATE_FORMAT( IF(DATEDIFF(CURDATE(),debut)<7,CURDATE(),debut) ,\'%d/%m/%Y\') FROM semaines WHERE colle AND debut <= CURDATE() ORDER BY debut DESC LIMIT 1');
if ( $resultat->num_rows )  {
  $r = $resultat->fetch_row();
  $select_semaines = str_replace("\"${r[0]}\"","\"${r[0]}\" selected",$select_semaines);
  $resultat->free();
}
$mysqli->close();

// Aide et formulaire d'ajout
?>

  <script type="text/javascript">
    dejanotesperso = <?php echo json_encode($notesperso); ?>;
    dejanotesautres = <?php echo json_encode($notesautres); ?>;
    dureecolles = <?php echo $matiere['dureecolles']; ?>;
    heurescolles = <?php echo $matiere['heurescolles']; ?>;
  </script>

  <form id="form-edite">
    <h3 class="edition">Modifier des notes</h3>
    <p class="ligne"><label for="jour">Jour dans le colloscope&nbsp;:</label><input type="text" name="jour" value="" size="8"></p>
    <p class="ligne"><label for="rattrapage">Jour de rattrapage si différent&nbsp;:</label><input type="text" name="rattrapage" value="" size="8"></p>
    <p class="ligne"><label for="duree">Durée (modifiée automatiquement)&nbsp;:</label><input type="text" name="duree" value="0h" size="4" value="0" readonly></p>
    <table></table>
    <p class="ligne"><label for="description">Description&nbsp;: </label><input type="text" name="description" value="" size="100" placeholder="Description de la séance (obligatoire)"></p>
  </form>

  <form id="form-ajoute" data-action="ajout-notescolles">
    <h3 class="edition">Ajouter des notes de colles</h3>
    <p class="ligne"><label for="sid">Semaine&nbsp;:</label>
      <select name="sid"><?php echo $select_semaines; ?>

      </select>
    </p>
    <p class="ligne"><label for="jour">Jour dans le colloscope&nbsp;:</label><input type="text" name="jour" value="<?php echo $r[1] ?? ''; ?>" size="8"></p>
    <p class="ligne"><label for="rattrapage">Jour de rattrapage si différent&nbsp;:</label><input type="text" name="rattrapage" value="" size="8"></p>
    <p class="ligne"><label for="duree">Durée (modifiée automatiquement)&nbsp;:</label><input type="text" name="duree" value="0h" size="4" value="0" readonly></p>
    <table></table>
    <p class="ligne"><label for="description">Description&nbsp;: </label><input type="text" name="description" value="" size="100" placeholder="Description de la séance (obligatoire)"></p>
    <p class="ligne"><label for="td">Séance de TD sans note&nbsp;: </label><input type="checkbox" name="td"></p>
  </form>

  <form id="form-notes">
    <table>
      <tbody>
<?php echo $table; ?>
      </tbody>
    </table>
    <div><select><option value="x"></option><option value="10">10</option><option value="11">11</option><option value="12">12</option><option value="13">13</option><option value="14">14</option><option value="15">15</option><option value="9">9</option><option value="8">8</option><option value="7">7</option><option value="6">6</option><option value="abs">Absent</option><option value="nn">Non noté</option><option value="16">16</option><option value="17">17</option><option value="18">18</option><option value="19">19</option><option value="20">20</option><option value="5">5</option><option value="4">4</option><option value="3">3</option><option value="2">2</option><option value="1">1</option><option value="0">0</option><option value="0,5">0,5</option><option value="1,5">1,5</option><option value="2,5">2,5</option><option value="3,5">3,5</option><option value="4,5">4,5</option><option value="5,5">5,5</option><option value="6,5">6,5</option><option value="7,5">7,5</option><option value="8,5">8,5</option><option value="9,5">9,5</option><option value="10,5">10,5</option><option value="11,5">11,5</option><option value="12,5">12,5</option><option value="13,5">13,5</option><option value="14,5">14,5</option><option value="15,5">15,5</option><option value="16,5">16,5</option><option value="17,5">17,5</option><option value="18,5">18,5</option><option value="19,5">19,5</option></select></div>
  </form>

<?php
if ( $autorisation == 5 )  {
?>
  <div id="aide-notescolles">
    <h3>Aide et explications</h3>
    <p>Il est possible ici consulter les notes, heures de colles et séances sans note que vous avez déclarées ou d'en ajouter en cliquant sur le bouton <span class="icon-ajoute"></span>.</p>
    <h4>Action spécifique à chaque colle</h4>
    <p>Chaque colle ou séance sans note saisie correspond à une ligne du tableau récapitulatif. Vous pouvez, à l'aide des boutons à droite de chaque ligne&nbsp;:</p>
    <ul>
      <li><span class="icon-edite"></span>&nbsp;: modifier la saisie. La date de la colle ne peut être déplacée en dehors de la semaine initialement saisie. Le reste (élèves interrogés, notes, commentaires, date de rattrapage) est modifiable sans restriction. Si la colle a déjà été relevée par l'administration, la liste des élèves interrogés n'est pas modifiable.</li>
      <li><span class="icon-montre"></span>&nbsp; montrer les commentaires de colle. Ce bouton n'apparaît que sur les lignes correspond à des colles où vous avez saisi des commentaires, marqués par un soulignement de l'élève concerné.</li>
      <li><span class="icon-supprime"></span>&nbsp;: supprimer la colle ou la séance de note. Ce n'est possible que si la colle n'a pas déjà été relevée par l'administration du lycée.</li>
    </ul>
    <h4>Saisie des notes</h4>
    <p>Vous pouvez saisir les notes de colle heure par heure ou en groupe sur une journée. La durée est calculée automatiquement selon le réglage que vous pouvez modifier sur la page de <a href="matieres">gestion des matières</a> ou directement en cliquant ici sur le bouton <em>Statistiques, liste des colles, réglages</em>. Ce même bouton vous permet d'accéder au tableau listant vos colles et celles de vos colleurs, où vous pourrez modifier la durée calculée.</p>
    <p>Il y a deux types de semaines&nbsp;:</p>
    <ul>
      <li>En «&nbsp;semaine de colles classiques&nbsp;», un élève ne peut avoir qu'une seule note par matière et par semaine. Dans les formulaires d'ajout ou de modification des notes, les élèves ayant déjà eu une note par un autre colleur lors de la semaine concernée sont grisés et non notables.</li>
      <li>En «&nbsp;semaine de préparation à l'oral&nbsp;», cette limitation n'existe plus et le fait qu'un élève ait déjà eu une note dans la semaine est simplement indiqué.</li>
    </ul>
    <p>Une fois saisie, une colle peut être modifiée mais doit rester sur la même semaine. S'il faut changer de semaine, il est nécessaire de supprimer la colle et de la recréer.</p>
    <h4>Saisie des séances sans note</h4>
    <p>Vous pouvez également déclarer et modifier des séances de cours ou de travaux dirigés sans note, qui seront alors payés comme des colles si le lycée utilise ces saisies pour mettre au paiement vos heures. Les séances sans note ne sont pas soumises aux mêmes restrictions de semaines que les colles.</p>
    <h4>Commentaires des colles</h4>
    <p>Vos colleurs peuvent comme vous ajouter de brefs commentaires pour chaque note de colle. Ces commentaires ne sont visibles que par vous. Les élèves n'y ont pas accès, l'administration du lycée non plus. Il est cependant déconseillé d'y laisser des mentions désobligeantes, et légalement interdit d'y mettre tout commentaire répréhensible. Vous avez la possibilité de lire ces commentaires inscrits par vos colleurs en cliquant ici sur le bouton <em>Statistiques, liste des colles, réglages</em>, mais pas de les modifier. Vous pouvez aussi en écrire, par exemple pour vous en souvenir à la prochaine colle, mais l'intérêt est peut-être faible.</p>
    <h4>Déclaration administrative</h4>
    <p>Si le lycée utilise ces saisies pour mettre au paiement les heures de colle, les durées déclarées comptent particulièrement. Le réglage à réaliser sur la page de <a href="matieres">gestion des matières</a> ou directement en cliquant ici sur le bouton <em>Statistiques, liste des colles, réglages</em> permet de spécifier le nombre de minutes par élève et si l'heure de colle est indivisible (un binôme = une heure même si un élève = 20 minutes) ou non (comptage des minutes sans arrondi à l'heure). Attention, les textes officiels précisent que chaque heure de colle est normalement indivisible, mais la question du budget global peut modifier cela. Il est nécessaire que l'ensemble de l'équipe pédagogique soit coordonnée sur ce point. L'administration a accès au détail des notes et au nombre d'élèves collés.</p>
    <p>Pour les colles relevées par l'administration, la date de la relève est inscrite dans le tableau. La colle reste modifiable sur les notes ou les commentaires, mais sa liste des élèves collés n'est plus modifiable et elle n'est plus ni supprimable.</p>
    <p>Toutes les valeurs de notes, y compris <em>Absent</em>, <em>Non noté</em> et <em>0</em>, sont comptabilisées et donnent lieu normalement à un paiement.</p>
    <p>Pour les séances sans note relevées par l'administration, la date de la relève est inscrite dans le tableau et la séance n'est plus modifiable sur sa durée ni supprimable. Il est par contre toujours possible de modifier le jour de la séance ou sa description.</p>
    <h4>Absences et rattrapages</h4>
    <p>Lorsqu'un élève est absent, il faut le noter dans la colle et le marquer <em>absent</em>. Si la colle peut être rattrapée, il faudra alors modifier cette note sans refaire une nouvelle saisie (une colle rattrapée n'est pas payée deux fois). Si la colle est relevée par l'administration entre l'absence et le rattrapage, ce n'est pas un problème&nbsp;: la note reste modifiable.</p>
    <p>Si la colle est réalisée au jour prévu par le colloscope, vous n'avez qu'une date à saisir. Si la colle a été déplacée, il est important que le <em>jour dans le colloscope</em> saisi soit bien la date initialement prévue, et que le <em>jour de rattrapage</em> soit la date effective de la colle. Les élèves ne peuvent pas avoir deux notes sur une même semaine dans une même matière.</p>
    <h4>Paramétrage</h4>
    <p>L'ensemble de cette fonctionnalité est paramétrable sur la page de <a href="matieres">gestion des matières</a>. Le mode de calcul de la durée de colle est propre à chaque matière. La liste des semaines correspondant à des colles est globale à toutes les matières, paramétrable <?php echo ( $_SESSION['admin'] ) ? 'sur la page de <a href="planning">gestion du planning</a>' : 'par les administrateurs du Cahier'; ?>.</p>
  </div>

<?php } else  { ?>
  <div id="aide-notescolles">
    <h3>Aide et explications</h3>
    <p>Il est possible ici consulter les notes, heures de colles et séances sans note que vous avez déclarées ou d'en ajouter en cliquant sur le bouton <span class="icon-ajoute"></span>.</p>
    <h4>Action spécifique à chaque colle</h4>
    <p>Chaque colle ou séance sans note saisie correspond à une ligne du tableau récapitulatif. Vous pouvez, à l'aide des boutons à droite de chaque ligne&nbsp;:</p>
    <ul>
      <li><span class="icon-edite"></span>&nbsp;: modifier la saisie. La date de la colle ne peut être déplacée en dehors de la semaine initialement saisie. Le reste (élèves interrogés, notes, commentaires, date de rattrapage) est modifiable sans restriction. Si la colle a déjà été relevée par l'administration, la liste des élèves interrogés n'est pas modifiable.</li>
      <li><span class="icon-montre"></span>&nbsp; montrer les commentaires de colle. Ce bouton n'apparaît que sur les lignes correspond à des colles où vous avez saisi des commentaires, marqués par un soulignement de l'élève concerné.</li>
      <li><span class="icon-supprime"></span>&nbsp;: supprimer la colle ou la séance de note. Ce n'est possible que si la colle n'a pas déjà été relevée par l'administration du lycée.</li>
    </ul>
    <h4>Saisie des notes</h4>
    <p>Vous pouvez saisir les notes de colle heure par heure ou en groupe sur une journée. La durée est calculée automatiquement selon le réglage réalisé par les professeurs.</p>
    <p>Il y a deux types de semaines&nbsp;:</p>
    <ul>
      <li>En «&nbsp;semaine de colles classiques&nbsp;», un élève ne peut avoir qu'une seule note par matière et par semaine. Dans les formulaires d'ajout ou de modification des notes, les élèves ayant déjà eu une note par un autre colleur lors de la semaine concernée sont grisés et non notables.</li>
      <li>En «&nbsp;semaine de préparation à l'oral&nbsp;», cette limitation n'existe plus et le fait qu'un élève ait déjà eu une note dans la semaine est simplement indiqué.</li>
    </ul>
    <p>Une fois saisie, une colle peut être modifiée mais doit rester sur la même semaine. S'il faut changer de semaine, il est nécessaire de supprimer la colle et de la recréer.</p>
    <h4>Saisie des séances sans note</h4>
    <p>Vous pouvez également déclarer et modifier des séances de cours ou de travaux dirigés sans note, qui seront alors payées comme des colles si le lycée utilise ces saisies pour mettre au paiement vos heures. Les séances sans note ne sont pas soumises aux mêmes restrictions de semaines que les colles.</p>
    <h4>Commentaires des colles</h4>
    <p>Il est possible d'ajouter de brefs commentaires pour chaque note de colle. Ces commentaires ne sont visibles que par vous et le(s) professeur(s) de la matière. Les élèves n'y ont pas accès, l'administration du lycée non plus. Il est cependant déconseillé d'y inscrire des mentions désobligeantes, et légalement interdit d'y mettre tout commentaire discriminant.</p>
    <h4>Vérification des professeurs</h4>
    <p>Les professeurs de la matière peuvent voir et télécharger le détail des notes que vous avez mises. Ils ont aussi la possibilité de corriger les durées de vos colles (pour les colles non encore relevées par l'administration du lycée), par exemple en cas de problème avec le calcul automatique. N'hésitez pas à leur en parler.</p>
    <p>Les professeurs de la matière peuvent aussi, même tardivement, modifier une note que vous avez mise.</p>
    <h4>Déclaration administrative</h4>   
    <p>Pour les colles relevées par l'administration, la date de la relève est inscrite dans le tableau. La colle reste modifiable sur les notes ou les commentaires, mais sa liste des élèves collés n'est plus modifiable et elle n'est plus ni supprimable.</p>
    <p>Pour les séances sans note relevées par l'administration, la date de la relève est inscrite dans le tableau et la séance n'est plus modifiable sur sa durée ni supprimable. Il est par contre toujours possible de modifier le jour de la séance ou sa description.</p>
    <p>Toutes les valeurs de notes, y compris <em>Absent</em>, <em>Non noté</em> et <em>0</em>, sont comptabilisées et donnent lieu normalement à un paiement.</p>
    <h4>Absences et retards</h4>
    <p>Lorsqu'un élève est absent, il faut le noter dans la colle et le marquer <em>absent</em>. Si la colle peut être rattrapée, il faudra alors modifier cette note sans refaire une nouvelle saisie (une colle rattrapée n'est pas payée deux fois). Si la colle est relevée par l'administration entre l'absence et le rattrapage, ce n'est pas un problème&nbsp;: la note reste modifiable.</p>
    <p>Si la colle est réalisée au jour prévu par le colloscope, vous n'avez qu'une date à saisir. Si la colle a été déplacée, il est important que le <em>jour dans le colloscope</em> saisi soit bien la date initialement prévue, et que le <em>jour de rattrapage</em> soit la date effective de la colle. Les élèves ne peuvent pas avoir deux notes sur une même semaine dans une même matière.</p>
  </div>


<?php } ?>
  
  <div id="aide-edite">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier, supprimer ou ajouter des notes (ou la description pour les séances sans note) de la colle sélectionnée. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <h4>Modification des notes de colles</h4>
    <p>La semaine déjà saisie n'est pas modifiable. Le jour de la colle est contraint dans cette semaine. S'il s'agit d'une erreur, la seule possibilité est de supprimer l'heure pour la recréer.</p>
    <p>Si la colle est réalisée au <em>jour</em> prévu par le colloscope, une seule date doit être saisie. Si la colle a été déplacée, il est important que le <em>jour dans le colloscope</em> saisi soit bien la date initialement prévue, et que le <em>jour de rattrapage</em> soit la date effective de la colle.</p>
    <p>En «&nbsp;semaine de colles classiques&nbsp;», un élève ne peut avoir qu'une seule note par matière et par semaine, les élèves ayant déjà eu une note par un autre colleur lors de la semaine concernée sont grisés et non notables. En «&nbsp;semaine de préparation à l'oral&nbsp;», cette limitation n'existe plus et le fait qu'un élève ait déjà eu une note dans la semaine est simplement indiqué. Ce réglage est réalisable par les utilisateurs ayant les droits d'administration du Cahier, sur la page de <a href="planning">gestion du planning</a>.</p>
    <p>Si la colle a déjà été relevée par l'administration du lycée, la liste des élèves notés n'est plus modifiable&nbsp;: il n'est pas possible de supprimer ni d'ajouter une note.</p>
    <p>Les élèves déjà notés apparaissent automatiquement. Vous pouvez supprimer leur note en vidant la case (premier choix dans la sélection). Les élèves sans note ne seront pas comptabilisés.</p>
    <p>Si des groupes de colles ont été définis, vous pouvez cocher les cases correspondantes à d'autres groupes de colle pour afficher d'autres élèves et ajouter des notes en notant les élèves que vous avez vus et en laissant les autres cases vides. Les élèves sans note ne seront pas comptabilisés. La durée de la colle est automatiquement mise à jour en fonction des élèves notés.</p>
    <p>Lorsqu'un élève est absent, il faut le noter <em>absent</em> dans la colle. Si la colle peut être rattrapée, il faudra alors modifier cette note sans refaire une nouvelle saisie (une colle rattrapée n'est pas payée deux fois). Si la colle est relevée par l'administration entre l'absence et le rattrapage, ce n'est pas un problème&nbsp;: la note reste modifiable.</p>
    <p>Toutes les valeurs de notes, y compris <em>Absent</em>, <em>Non noté</em> et <em>0</em>, sont comptabilisées et donnent lieu normalement à un paiement.</p>
    <h4>Commentaires des colles</h4>
    <p>Il est possible d'ajouter de brefs commentaires en cochant la case <em>Ajouter des commentaires</em> pour chaque note de colle. Ces commentaires ne sont visibles que par vous et le(s) professeur(s) de la matière. Les élèves n'y ont pas accès, l'administration du lycée non plus. Il est cependant déconseillé d'y inscrire des mentions désobligeantes, et légalement interdit d'y mettre tout commentaire discriminant.</p>
    <p>Il n'est pas obligatoire de saisir un commentaire pour chaque note, seuls les commentaires non vides sont enregistrés.</p>
    <h4>Modification des séances sans note</h4>
    <p>Le <em>jour</em> et la <em>description</em> de la séance sont modifiables sans condition. La <em>description</em> permet de vérifier qu'il s'agit bien d'une séance qui correspond à un paiement en heures de colles. Sa saisie est obligatoire.</p>
    <p>La <em>durée</em> de la séance n'est modifiable que si la séance n'a pas encore été relevée par l'administration du lycée.</p>
  </div>

  <div id="aide-ajoute">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet d'ajouter des notes de colles ou de déclarer une séance sans note. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <h4>Saisie des notes de colles</h4>
    <p>Pour des notes de colles, vous devez commencer par choisir la <em>semaine</em> correspondant aux notes que vous allez saisir. Vous pouvez ensuite choisir le jour dans cette semaine. La date d'aujourd'hui est donnée par défaut si des colles sont prévues dans le planning cette semaine.</p>
    <p>Si la colle est réalisée au <em>jour</em> prévu par le colloscope, vous n'avez qu'une date à saisir. Si la colle a été déplacée, il est important que le <em>jour dans le colloscope</em> saisi soit bien la date initialement prévue, et que le <em>jour de rattrapage</em> soit la date effective de la colle.</p>
    <p>En «&nbsp;semaine de colles classiques&nbsp;», un élève ne peut avoir qu'une seule note par matière et par semaine, les élèves ayant déjà eu une note par un autre colleur lors de la semaine concernée sont grisés et non notables. En «&nbsp;semaine de préparation à l'oral&nbsp;», cette limitation n'existe plus et le fait qu'un élève ait déjà eu une note dans la semaine est simplement indiqué. Ce réglage est réalisable par les utilisateurs ayant les droits d'administration du Cahier, sur la page de <a href="planning">gestion du planning</a>.</p>
    <p>Si des groupes de colles ont été définis, vous pouvez cocher les cases correspondantes pour n'afficher que les élèves de ces groupes-là.</p>
    <p>Si ce n'est pas le cas, ce n'est pas un problème&nbsp;: vous pouvez mettre des notes aux élèves que vous avez vus et laisser les autres cases vides. Les élèves sans note ne seront bien sûr pas comptabilisés.</p>
    <p>Si les élèves que vous avez vus sont à cheval sur plusieurs groupes, vous pouvez cocher les cases de ces groupes pour afficher les élèves et laisser vides les cases des élèves non vus. Les élèves sans note ne seront pas comptabilisés.</p>
    <p>Vous pouvez saisir les notes de colle heure par heure ou en groupe sur une journée. La durée est calculée automatiquement selon le réglage réalisé par les professeurs.</p>
    <p>Lorsqu'un élève est absent, il faut le noter <em>absent</em> dans la colle. Si la colle peut être rattrapée, il faudra alors modifier cette note sans refaire une nouvelle saisie (une colle rattrapée n'est pas payée deux fois). Si la colle est relevée par l'administration entre l'absence et le rattrapage, ce n'est pas un problème&nbsp;: la note reste modifiable.</p>
    <p>Toutes les valeurs de notes, y compris <em>Absent</em>, <em>Non noté</em> et <em>0</em>, sont comptabilisées et donnent lieu normalement à un paiement.</p>
    <h4>Commentaires des colles</h4>
    <p>Il est possible d'ajouter de brefs commentaires en cochant la case <em>Ajouter des commentaires</em> pour chaque note de colle. Ces commentaires ne sont visibles que par vous et le(s) professeur(s) de la matière. Les élèves n'y ont pas accès, l'administration du lycée non plus. Il est cependant déconseillé d'y inscrire des mentions désobligeantes, et légalement interdit d'y mettre tout commentaire discriminant.</p>
    <p>Il n'est pas obligatoire de saisir un commentaire pour chaque note, seuls les commentaires non vides sont enregistrés.</p>
    <h4>Saisie des séances sans note</h4>
    <p>Vous pouvez également déclarer une séance de cours ou de travaux dirigés sans note en cochant la case <em>Séance de TD sans note</em>. Ces heures sont prévues pour être relevées et payées comme des colles si le lycée utilise ces saisies pour mettre au paiement vos heures. Les séances sans note ne sont pas soumises aux mêmes restrictions de semaines que les colles.</p>
    <p>La <em>description</em> de ces séances permet de vérifier qu'il s'agit bien d'une séance qui correspond à un paiement en heures de colles. Sa saisie est obligatoire.</p>
  </div>

<?php
fin(true,false,'datetimepicker');
?>
