<?php
// Sécurité
define('OK',1);
// Configuration
include('config.php');
// Fonctions
include('fonctions.php');

//////////////////////////////////////////////
// Commentaires : protection des transferts //
//////////////////////////////////////////////
/* L'activation de la fonction est stockée pour chaque matière dans la table
 * matière (colonne transferts) et pour les transferts généraux dans la table
 * prefs (nom=transferts_general).  
 * Les valeurs de protection globales sont stockées aux mêmes endroits
 * (colonne transferts_protection et nom=transferts_general_protection).
 * Les valeurs de protection globales sont mises à jour à chaque ajout/suppr
 * d'un transfert, comme le dénominateur commun des transferts existants.
 * Les profs éventuellement associés à la matière et les élèves ont toujours
 * accès, les invités jamais. 
 * Code : sens * 1 + autorisation_colleurs * 2 + autorisation_lycée * 4
 *                 + autorisation_profs_non_associés * 8
 * Sens : 0 si élèves vers encadrants, 1 si encadrants vers élèves
 * Autorisations : 0 si non, 1 si oui
 * Pour les transferts généraux, valeur max 7. Avec matière, 15.
 */

// Mode lecture
$admin = $autorisation && $_SESSION['admin'];
$mode_lecture = 0;
$icones = '';
// Mise en place des icônes générales
// Valable pour les deux premières parties (descriptif matières ou erreurs)
// et transferts-eleves.php en mode lecture
if ( ( $autorisation == 5 ) || $admin )  {
  $donnees = array('action'=>'transferts','matiere'=>0,'protection'=>0,'edition'=>0);
  $mode_lecture = $_SESSION['mode_lecture'];
  $icones = "\n  <div id=\"icones\">\n    <a class=\"icon-lecture".($mode_lecture ? ' mev' : '')."\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n";
  $editionjs = true;
}

//////////////////
// Autorisation //
//////////////////
$mysqli = connectsql();
// Accès aux professeurs, lycée, colleurs, élèves connectés uniquement
if ( !$autorisation )  {
  $titre = 'Transferts de documents personnels';
  $actuel = false;
  include('login.php');
}

////////////////////////////////
// Récupération d'un document //
////////////////////////////////
if ( ctype_digit($id = $_REQUEST['dl'] ?? '') && ctype_digit($tid = $_REQUEST['t'] ?? '') && isset($_REQUEST['verif']) && ( $verif = $_REQUEST['verif'] )
  || strpos($id,',') && ( count($params = explode(',',$id)) == 3 ) && ( list($id,$tid,$verif) = $params ) && ctype_digit($id) && ctype_digit($tid) ) {
  
  // Pas en mode lecture
  if ( $mode_lecture )  {
    // Téléchargement groupé pour être mis en zip -> pas d'affichage de page
    if ( isset($_REQUEST['zip']) )
      exit('{"etat":"nok","message":"Les téléchargements ne sont pas possible en mode lecture."}');
    debut($mysqli,'Transferts de documents personnels','Les téléchargements ne sont pas possible en mode lecture.',$autorisation,' ',$donnees);
    echo $icones;
    fin(true);
  }
  
  // Vérification de l'identifiant du document
  $requete = ( $autorisation == 2 ) ? "eleve = ${_SESSION['id']} AND dispo < NOW()" : 'FIND_IN_SET(matiere,\''.str_replace('c','',$_SESSION['matieres']).'\')';
  $resultat = $mysqli->query("SELECT type, lien, prefixe, matiere, eleve, utilisateur, ext, numero, taille, upload
                              FROM transdocs LEFT JOIN transferts ON transfert = transferts.id
                              WHERE transdocs.id = $id AND transfert = $tid AND $requete");
  if ( !$resultat->num_rows )  {
    // Téléchargement groupé pour être mis en zip -> pas d'affichage de page
    if ( isset($_REQUEST['zip']) )
      exit('{"etat":"nok","message":"Mauvais paramètre d\'accès à cette page."}');
    debut($mysqli,'Transfert de documents','Mauvais paramètre d\'accès à cette page.',$autorisation,'transferts');
    $mysqli->close();
    fin();
  }
  $r = $resultat->fetch_assoc();
  $resultat->free();

  // Vérification du code de téléchargement
  // Cela permet d'être sûr qu'on a droit à voir ce document
  if ( $verif != sha1("d$id-${r['eleve']}-${r['lien']}-$mdp") )  {
    // Téléchargement groupé pour être mis en zip -> pas d'affichage de page
    if ( isset($_REQUEST['zip']) )
      exit('{"etat":"nok","message":"Mauvais paramètre d\'accès à cette page."}');
    debut($mysqli,'Transfert de documents','Mauvais paramètre d\'accès à cette page.',$autorisation,'transferts');
    $mysqli->close();
    fin();
  }

  // Récupération du nom de l'élève
  if ( $autorisation > 2 )  {
    $resultat = $mysqli->query("SELECT CONCAT(nom, ' ', prenom) AS nom FROM utilisateurs WHERE id = ${r["eleve"]}");
    $eleve = ' - '.$resultat->fetch_row()[0];
    $resultat->free();
  }
  else
    $eleve = '';

  // Récupération du nombre de documents de l'élève : si un seul, pas de numérotation
  $resultat = $mysqli->query("SELECT * FROM transdocs WHERE transfert = $tid AND eleve = ${r['eleve']}");
  $nom = ( $resultat->num_rows > 1 ) ? "${r['prefixe']}$eleve - ${r['numero']}.${r['ext']}" : "${r['prefixe']}$eleve.${r['ext']}";
  $resultat->free();
  
  // Visualisation/Téléchargement (presque identique à download.php)
  $type = transforme_extension($r['ext'],1);
  // Récupération en ligne forcée (utile pour les vidéos/audios)
  if ( isset($_REQUEST['inline']) )
    $attachment = 'inline';
  // Visualisation forcée
  elseif ( isset($_REQUEST['voir']) )  {
    if ( substr($type,0,5) == 'video' )  {
      debut($mysqli,'Transferts de documents personnels',$message,$autorisation,'transferts',$donnees);
      $mysqli->close();
      $date = ucfirst(format_date($r['upload']));
      echo <<<FIN
    <article class="centre">
      <video src="transferts?dl=$id&amp;t=$tid&amp;verif=$verif&amp;inline" type="$type" controls>Votre navigateur n'affiche pas les fichiers vidéo en HTML5.</video>
    </article>
    
    <article>
      <h4>Détails de la vidéo</h4>
      <p><strong>Taille</strong>&nbsp;: ${r['taille']}</p>
      <p><strong>Publication</strong>&nbsp;: $date</p>
      <p class="centre"><a class="icon-download" href="transferts?dl=$id&amp;t=$tid&amp;verif=$verif" title="Télécharger ce document"></a></p>
    </article>

  FIN;
      fin();
    }
    elseif ( substr($type,0,5) == 'audio' )  {
      debut($mysqli,'Transferts de documents personnels',$message,$autorisation,'transferts',$donnees);
      $mysqli->close();
      $date = ucfirst(format_date($r['upload']));
      echo <<<FIN
    <article class="centre">
      <audio src="transferts?dl=$id&amp;t=$tid&amp;verif=$verif&amp;inline" type="$type" controls>Votre navigateur n'affiche pas les fichiers audio en HTML5.</video>
    </article>
    
    <article>
      <h4>Détails du fichier audio</h4>
      <p><strong>Taille</strong>&nbsp;: ${r['taille']}</p>
      <p><strong>Publication</strong>&nbsp;: $date</p>
      <p class="centre"><a class="icon-download" href="transferts?dl=$id&amp;t=$tid&amp;verif=$verif" title="Télécharger ce document"></a></p>
    </article>

  FIN;
      fin();
    }
    elseif ( ( $r['ext'] == 'py' ) && !headers_sent() )  {
      header("Location: https://$domaine/basthon/?from=${chemin}transferts?dl=$id,$tid,$verif");
      exit;
    }
    elseif ( ( $r['ext'] == 'sql' ) && !headers_sent() )  {
      header("Location: https://$domaine/basthon/?kernel=sql&from=${chemin}transferts?dl=$id,$tid,$verif");
      exit;
    }
    elseif ( ( $r['ext'] == 'ml' ) && !headers_sent() )  {
      header("Location: https://$domaine/basthon/?kernel=ocaml&from=${chemin}transferts?dl=$id,$tid,$verif");
      exit;
    }
    else
      $attachment = 'inline';
  }
  else
    $attachment = 'attachment';

  // Mise à disposition du fichier
  header("Content-Type: $type");
  header("Content-Disposition: $attachment; filename=\"".rawurlencode($nom).'"');
  readfile("documents/${r['lien']}/${r['eleve']}_$id.${r['ext']}");
  exit();
}

// Table étendue des matières
$resultat = $mysqli->query('SELECT GROUP_CONCAT(val) FROM prefs WHERE nom LIKE "transferts%" ORDER BY nom');
list($transferts,$protection) = explode(',',$resultat->fetch_row()[0]);
$resultat->free();
$tablematieres = ( $transferts < 2 ) ? "( ( SELECT id, ordre, cle, nom, transferts, transferts_protection FROM matieres) UNION (SELECT  0, 0, 'general', 'Général', $transferts, $protection ) ) AS m" : 'matieres AS m';

// Restrictions d'affichage de la liste et début HTML
// genere_recherche($mysqli,$autorisation,$cle) retourne la requete de
// sélection demandée par l'utilisateur avec les paramètres de la page ; elle
// affiche simultanément la topbarre de recherche, donc à positionner juste
// après debut(). Fonction utilisée ici et dans transferts-eleves.php
// $cle est la clé de la matière ("general" si pas de matière associée).
function genere_recherche($mysqli,$autorisation,$cle)  {
  // Restrictions sur la sélection des transferts, venant de la barre de recherche
  $requete = '';
  $select_types = '<option value="encours">Transferts en cours</option><option value="termines">Transferts terminés</option>';
  switch ( $_REQUEST['type'] ?? '' )  {
    case 'encours':  $requete = 'AND deadline>NOW()'; $select_types = str_replace('encours"','encours" selected',$select_types);   break;
    case 'termines': $requete = 'AND deadline<NOW()'; $select_types = str_replace('termines"','termines" selected',$select_types);
  }

  // Sens : type&1 = 0 si depuis les élèves, 1 si vers les élèves
  $select_sens = '<option value="vers">envoyés vers vous</option><option value="depuis">envoyés par vous</option>';
  switch ( $_REQUEST['sens'] ?? '' )  {
    case 'vers':   $requete .= ' AND ( type & 1 ) = '.intval( $autorisation == 2 ); $select_sens = str_replace('vers"','vers" selected',$select_sens); break;
    case 'depuis': $requete .= ' AND ( type & 1 ) = '.intval( $autorisation != 2 ); $select_sens = str_replace('depuis"','depuis" selected',$select_sens);
  }

  // Recherche sur du texte
  $recherche = '';
  if ( $_REQUEST['recherche'] ?? '' )
    $requete .= ' AND ( titre LIKE "%'.$mysqli->real_escape_string($recherche = htmlspecialchars($_REQUEST['recherche'])).'%" OR indications LIKE "%'.$mysqli->real_escape_string($recherche).'%" )';

  // Ordre d'affichage des transferts - CLE est à remplacer par $cle à l'affichage
  $iconesordre = <<< FIN
    <a class="icon-chronodesc" onclick="window.location='?$cle&amp;ordre=chrono-inv&amp;type='+$('#type').val()+'&amp;sens='+$('#sens').val()+'&amp;recherche='+$('.topbarre input').val();" title="Classer les transferts par ordre chronologique inversé"></a>
    <a class="icon-chronoasc" onclick="window.location='?$cle&amp;ordre=chrono&amp;type='+$('#type').val()+'&amp;sens='+$('#sens').val()+'&amp;recherche='+$('.topbarre input').val();" title="Classer les transferts par ordre chronologique"></a>
    <a class="icon-alphadesc" onclick="window.location='?$cle&amp;ordre=alpha-inv&amp;type='+$('#type').val()+'&amp;sens='+$('#sens').val()+'&amp;recherche='+$('.topbarre input').val();" title="Classer les transferts par ordre alphabétique inversé"></a>
    <a class="icon-alphaasc" onclick="window.location='?$cle&amp;ordre=alpha&amp;type='+$('#type').val()+'&amp;sens='+$('#sens').val()+'&amp;recherche='+$('.topbarre input').val();" title="Classer les transferts par ordre alphabétique"></a>
FIN;
  switch ( $_REQUEST['ordre'] ?? '' )  {
    case 'alpha':      $requete .= ' ORDER BY prefixe ASC';   $ordre = 'alphaasc'; break;
    case 'alpha-inv':  $requete .= ' ORDER BY prefixe DESC';  $ordre = 'alphadesc'; break;
    case 'chrono':     $requete .= ' ORDER BY deadline ASC, dispo ASC';  $ordre = 'chronoasc'; break;
    case 'chrono-inv': $requete .= ' ORDER BY deadline DESC, dispo DESC';  $ordre = 'chronodesc'; break;
    default:           $ordre = 'chronodesc'; // Pas de requête pour l'affichage de la page vide
  }
  $iconesordre = str_replace($ordre,"$ordre actuel",$iconesordre);

  // Barre de recherche
  echo <<< FIN

  <p id="recherchetransfert" class="topbarre">
$iconesordre
    <select id="type" onchange="$('.topbarre .actuel').click();">
      <option value="tout">Tous les transferts</option>$select_types
    </select>
    <select id="sens" onchange="$('.topbarre .actuel').click();">
      <option value="tout">dans les deux sens</option>$select_sens
    </select>
    <span class="icon-recherche" onclick="$('.topbarre .actuel').click();"></span>
    <input type="text" value="$recherche" onchange="$('.topbarre .actuel').click();" placeholder="Rechercher un mot...">
  </p>

FIN;
  return $requete;
}

/////////////////////////////////////////
// Affichage différent pour les élèves //
/////////////////////////////////////////
// transferts-eleves.php contient fin()
if ( $autorisation == 2 )
  include('transferts-eleves.php');
// Accès interdit pour les comptes invités
if ( $autorisation == 1 )  {
  debut($mysqli,'Transferts de documents personnels','Vous n\'avez pas accès à cette page.',$autorisation,' ');
  $mysqli->close();
  fin();
}

/////////////////////////////////////////////////
// Pas de matière demandée : affichage spécial //
/////////////////////////////////////////////////
// Affichage de la liste des matières si plusieurs
// possibles, des transferts de la matière si une seule
// Ici, on est forcément colleur, lycée, prof, admin ou en mode lecture
if ( empty($_GET) )  { 
  if ( ( $autorisation == 5 ) && !$mode_lecture )  {
    $requete = 'transferts < 2';
    // Cas des profs étant colleurs dans d'autres matières
    if ( strpos($_SESSION['matieres'],'c') )  {
      $m = str_replace('c','',implode(',',array_filter(explode(',',$_SESSION['matieres']),function($v){return $v[0]=='c';})));
      $requete = "transferts < 2 AND FIND_IN_SET(m.id,'${_SESSION['matieres']}') OR transferts = 1 AND (32-transferts_protection) & 4 AND type & 2 AND FIND_IN_SET(m.id,'$m')";
    }
  }
  else  {
    $a = ( $mode_lecture ) ? $mode_lecture-2 : $autorisation-1;
    $requete = "transferts = 1 AND (32-transferts_protection)>>$a & 1 AND type>>($a-1) & 1 AND FIND_IN_SET(m.id,'${_SESSION['matieres']}')";
  }
  $resultat = $mysqli->query("SELECT m.id, cle, nom, transferts, transferts_protection AS protection, COUNT(t.id) AS n
                              FROM $tablematieres LEFT JOIN transferts AS t ON m.id = t.matiere
                              WHERE $requete GROUP BY m.id ORDER BY ordre");
  if ( $n = $resultat->num_rows )  {
    // Si une seule matière trouvée, réglage automatique sur cette matière
    if ( $n == 1 )  {
      $matiere = $resultat->fetch_assoc();
      $resultat->free();
    }
    // Si plusieurs matières trouvées, choix à faire.
    else  {
      // transferts-eleves.php contient fin()
      if ( $mode_lecture == 3 )
        include('transferts-eleves.php');
      debut($mysqli,'Transferts de documents personnels',$message,$autorisation,'transferts',$donnees ?? false);
      echo "$icones\n  <article>\n    <h2>Mes matières</h2>";
      $pasdetransfert = 0;
      $matieres = array();
      while ( $r = $resultat->fetch_assoc() )  {
        if ( $r['transferts'] )  {
          $matieres[] = $r;
          if ( $mode_lecture )
            echo "\n    <h3 class=\"detailmatiere\"><a href=\"transferts?${r['cle']}\">${r['nom']}</a></h3>";
          else  {
            $detail = ( $r['n'] == 1 ) ? '(Un seul transfert déjà existant)' : "(${r['n']} transferts déjà existants)";
            echo "\n    <h3 class=\"detailmatiere\"><a href=\"transferts?${r['cle']}\">${r['nom']}</a><span>$detail</span></h3>";
          }
        }
        // Visible uniquement par les profs associés à la matière
        elseif ( ( $autorisation == 5 ) && in_array($r['id'],explode(',',$_SESSION['matieres'])) )  {
          echo "\n    <h3 class=\"detailmatiere\"><a href=\"transferts?${r['cle']}\">${r['nom']}</a><span>(Aucun transfert pour l'instant)</span></h3>";
          $pasdetransfert += 1;
        }
      }
      if ( ( $autorisation == 5 ) && $pasdetransfert )
        echo "\n    <p>".( ( $pasdetransfert == 1 ) ? 'Une matière ne contient' : "$pasdetransfert matières ne contiennent" ).' pas encore de transfert de documents. Vous pouvez désactiver la fonction «&nbsp;transferts de documents&nbsp;» pour les matières où vous ne comptez pas l\'utiliser dans les <a href="matieres">réglages de vos matières</a>. Cela évite les affichages non nécessaires.</p>';
      echo "\n  </article>\n";
      $resultat->free();
      // Affichage pour chaque matière de la liste des transferts visibles
      foreach ( $matieres as $matiere )  {
        if ( $mode_lecture )
          $autorisation = $mode_lecture - 1;
        $requete_autorisation = ( ( $autorisation == 5 ) && in_array($matiere['id'],explode(',',$_SESSION['matieres'])) ) ? '' : "AND ( type>>($autorisation-2) & 1 )";
        $resultat = $mysqli->query("SELECT id, type & 1 as envoi, titre, deadline>NOW() AS encours, dispo>NOW() AS bientot,
                                     IF(YEAR(deadline) < 2100, DATE_FORMAT(deadline,'%d/%m/%Y %kh%i'), 'Pas d\'échéance') AS limite,
                                     DATE_FORMAT(dispo,'%w%Y%m%e') AS date_from, DATE_FORMAT(dispo,' à %kh%i') AS heure_from
                                     FROM transferts WHERE matiere = ${matiere['id']} $requete_autorisation ORDER BY deadline DESC, dispo DESC");
        if ( $n = $resultat->num_rows )  {
          $titre = ( $matiere['id'] ) ? "Transferts de documents en <a href=\"transferts?${matiere['cle']}\">${matiere['nom']}</a>" : 'Transferts de <a href="transferts?general">documents généraux</a>';
          echo  <<<FIN

  <article>
    <h3 class="titrematiere">$titre</h3>
    <table id="transferts">
      <thead>
        <tr><th>État</th><th>Titre</th><th>Sens</th><th>Date limite</th><th>Nb d'élèves</th></tr>
      </thead>
      <tbody>
FIN;
          while ( $r = $resultat->fetch_assoc() )  {
            // État : actif (caractère "lecture" &#9654;), arrêté (caractère "stop" &#9632;), différé (icon-recent)
            if ( $r['bientot'] )
              $etat = '<span class="icon-recent" title="Visible à partir du '.format_date($r['date_from']).$r['heure_from'].'"></span>';
            else
              $etat = ( $r['encours'] ) ? '<span class="icon-encours" title="En cours"></span>' : '<span class="icon-stop" title="Terminé"></span>';
            // Sens
            $sens = ( $r['envoi'] ) ? '<span title="Des encadrants vers les élèves">&rarr;</span>' : '<span title="Des élèves vers les encadrants">&larr;</span>';
            // Nombre d'élèves
            $resultat1 = $mysqli->query("SELECT COUNT(DISTINCT eleve) FROM transdocs WHERE transfert = ${r['id']}");
            $nb = $resultat1->fetch_row()[0];
            $resultat1->free();
            // Affichage
            echo "\n        <tr><td>$etat</td><td>${r['titre']}</td><td>$sens</td><td>${r['limite']}</td><td>$nb</td></tr>";
          }
          $resultat->free();
          echo  <<< FIN

      </tbody>
    </table>
  </article>

FIN;
        }
      }
      fin($editionjs ?? false);
    }
  }
  // Pas de matière concernée !
  else  {
    debut($mysqli,'Transferts de documents personnels','Cette page ne contient aucune information.',$autorisation,' ',$donnees ?? false);
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
// transferts=0 : pas de transfert saisi
// transferts=1 : déjà des transferts saisis
// transferts=2 : fonction désactivée, pas d'affichage
if ( !isset($matiere) )  {
  $resultat = $mysqli->query("SELECT id, cle, nom, transferts_protection AS protection FROM $tablematieres 
                              WHERE transferts < 2" . ( $autorisation == 5 ? '' : " AND FIND_IN_SET(id,'${_SESSION['matieres']}')" ) );
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
      debut($mysqli,'Transferts de documents personnels','Mauvais paramètre d\'accès à cette page.',$autorisation,' ',$donnees ?? false);
      $mysqli->close();
      echo $icones;
      fin($editionjs ?? false);
    }
  }
  // Si aucune matière avec des transferts n'est enregistrée
  else  {
    debut($mysqli,'Transferts de documents personnels','Cette page ne contient aucune information.',$autorisation,' ',$donnees ?? false);
    $mysqli->close();
    echo $icones;
    fin($editionjs ?? false);
  }
}
$mid = $matiere['id'];
$cle = $matiere['cle'];

// Récupération de la liste des élèves pour 
// * l'affichage des élèves non concernés à chaque transfert
// * la construction du tableau d'envoi de corrections en javascript
$resultat = $mysqli->query("SELECT id, CONCAT(nom,',',prenom) AS eleve FROM utilisateurs WHERE autorisation = 2 AND mdp > '0' AND FIND_IN_SET($mid,matieres) ORDER BY IF(LENGTH(nom),nom,login)");
$eids = array();
$enoms = array();
if ( $nbeleves = $resultat->num_rows )  {
  while ( $r = $resultat->fetch_row() )  {
    $eids[] = intval($r[0]);
    $enoms[] = $r[1];
  }
  $resultat->free();
}

////////////
/// HTML ///
////////////
// Données globales par défaut
// MathJax désactivé par défaut
$mathjax = false;
$icones = '';
// Pour les profs : modifications dont dates des transferts
// Pour les autres : modifications (seulement si transfert autorisé)
// L'accès ici est impossible si on est non connecté, invité ou élève. 
$donnees = array('action'=>'transferts','matiere'=>$mid);
if ( $edition = acces($matiere['protection'],$mid,"Transferts de documents personnels - ${matiere['nom']}","transferts?$cle",$mysqli) )  {
  $donnees['css'] = 'datetimepicker';
  $icones = "\n    <a class=\"icon-ajoute formulaire\" title=\"Ajouter un transfert\"></a>\n    <a class=\"icon-lecture\" title=\"Modifier le mode de lecture\"></a>";
}
if ( $mode_lecture )  {
  // Accès non autorisé : on ne peut être en mode lecture que si on est 
  // propriétaire de la page ou administrateur
  if ( ( $edition != 2 ) && !$admin )  {
    debut($mysqli,$titre,'Vous n\'avez pas accès à cette page.',5,' ');
    $mysqli->close();
    fin();
  }
  // Mode lecture pour les élèves
  $donnees['protection'] = $donnees['edition'] = 0;
  $icones = '<a class="icon-lecture mev" title="Modifier le mode de lecture"></a>';
  // transferts-eleves.php contient fin()
  if ( $mode_lecture == 3 )
    include('transferts-eleves.php');
  // Mode lecture pour les utilisateurs interdits
  elseif ( ( $mode_lecture < 3 ) || !( (32-$matiere['protection']) >> ($mode_lecture-2) & 1 ) )  {
    debut($mysqli,'Transferts de documents personnels',$message,$autorisation,"transferts?$cle",$donnees);
    $mysqli->close();
    echo "  <div id=\"icones\">$icones</div>\n  <article><h2>Cette page n'est pas autorisée pour ce type d'utilisateur.</h2></article>\n\n";
    fin(true);
  }
  // On supprime les icônes d'édition sur chaque transfert
  $edition = false;
  $autorisation = $mode_lecture - 1;
}
debut($mysqli,"Transferts de documents personnels - ${matiere['nom']}",$message,$autorisation,"transferts?$cle",$donnees);

// Icônes générales
echo <<< FIN

  <div id="icones" data-action="page">$icones
    <a class="icon-aide" title="Aide pour les transfers de documents personnels"></a>
  </div>

FIN;

// Barre de recherche
$requete = genere_recherche($mysqli,$autorisation,$cle);

// Récupération de l'ensemble des transferts
// deadline est fixée à 2100-01-01 pour les transferts sans échéance
$requete_autorisation = ( $edition == 2 ) ? '' : "AND ( type>>($autorisation-2) & 1 )";
$resultat = $mysqli->query("SELECT id, type & 1 as envoi, type | 1 as type, titre, prefixe, lien, indications,
                             deadline>NOW() AS encours, IF(YEAR(deadline) < 2100, DATE_FORMAT(deadline,'%d/%m/%Y %kh%i'), 0) AS limite,
                             DATE_FORMAT(deadline,'%w%Y%m%e') AS date_to, DATE_FORMAT(deadline,'%kh%i') AS heure_to,
                             dispo>NOW() AS bientot, IF(dispo,DATE_FORMAT(dispo,'%e/%m/%Y %kh%i'),0) AS dispo,
                             DATE_FORMAT(dispo,'%w%Y%m%e') AS date_from, DATE_FORMAT(dispo,'%kh%i') AS heure_from
                             FROM transferts WHERE matiere = $mid $requete_autorisation ". ( $requete ?: 'ORDER BY deadline DESC, dispo DESC' ) );
// On force le bit le plus faible à être présent par "type | 1"
$comptes = array('professeurs','colleurs','comptes de type lycée','professeurs (non associés à la matière)');
if ( $n = $resultat->num_rows )  {
  $articles = $table = '';
  while ( $r = $resultat->fetch_assoc() )  {
    // Récupération du nombre de participants
    $resultat1 = $mysqli->query("SELECT COUNT(DISTINCT eleve), COUNT(id), COUNT(NULLIF(utilisateur,${_SESSION['id']})),
                                 GROUP_CONCAT(DISTINCT eleve) FROM transdocs WHERE transfert = ${r['id']}");
    list($nbpresents, $nbdocs, $nbperso, $presents_ids) = $resultat1->fetch_row();
    $resultat1->free();
    // Récupération des listes de présents et d'absents
    // On ne génère $presents que si 1/4 des élèves sont présents
    // On ne génère $absents que si 1/4 des élèves sont absents
    $presents_ids = explode(',',$presents_ids);
    $nbabsents = count($absents_ids = array_diff($eids, $presents_ids)); 
    $presents = $absents = array();
    if ( $nbpresents < $nbeleves/4 )  {
      for ( $i = 0 ; $i < $nbeleves ; $i++ )
        if ( in_array($eids[$i], $presents_ids) )  {
          $nom = explode(',',$enoms[$i]);
          $presents[] = $nom[1].' '.$nom[0];
        }
      $presents = implode(', ', $presents);
    }
    elseif ( $nbabsents < $nbeleves/4 )  {
      for ( $i = 0 ; $i < $nbeleves ; $i++ )
        if ( in_array($eids[$i], $absents_ids) )  {
          $nom = explode(',',$enoms[$i]);
          $absents[] = $nom[1].' '.$nom[0];
        }
      $absents = implode(', ', $absents);
    }
    $presents_ids = implode(',', $presents_ids);
    $absents_ids = implode(',', $absents_ids);
    // Pour les profs, icones de modification/suppression
    $iconesmodifier = ( $edition == 2 ) ? "\n    <a class=\"icon-supprime\" title=\"Supprimer ce transfert\"></a>\n    <a class=\"icon-edite formulaire\" title=\"Modifier ce transfert\"></a>"
                                        : '';
    // Dates
    if ( $r['bientot'] )  {
      $classe = ' nodispo';
      $date_from = format_date($r['date_from']);
      $heure_from = ( $r['heure_from'] == '0h00' ? '' : " à ${r['heure_from']}" ); 
      $etat = "<span class=\"icon-recent\" title=\"Visible à partir du $date_from$heure_from\"></span>";
      $horaires = "Ce transfert n'est <span class=\"mev\">pas encore visible</span> des élèves, ne le sera qu'<strong>à partir du $date_from$heure_from</strong>.";
      if ( $r['limite'] ) 
        $horaires .= ( $r['envoi'] ) ? ' Vous pouvez cependant déjà envoyer des documents et le faire <strong>jusqu\'au '.format_date($r['date_to'])." à ${r['heure_to']}</strong>."
                                     : ' Les élèves pourront alors envoyer des documents <strong>jusqu\'au '.format_date($r['date_to'])." à ${r['heure_to']}</strong>.";
      elseif ( $r['envoi'] )
        $horaires .= ' Vous pouvez cependant déjà envoyer des documents.';
    }
    else  {
      $classe = '';
      if ( $r['encours'] )  {
        $etat = '<span class="icon-encours" title="En cours"></span>';
        $horaires = 'Ce transfert est <span class="ok">&nbsp;en cours&nbsp;</span>.';
        if ( $r['limite'] )
          $horaires .= ( $r['envoi'] ) ? ' Vous pouvez envoyer des documents <strong>jusqu\'au '.format_date($r['date_to'])." à ${r['heure_to']}</strong>."
                                       : ' Les élèves peuvent envoyer des documents <strong>jusqu\'au '.format_date($r['date_to'])." à ${r['heure_to']}</strong>.";
      }
      else  {
        $etat = '<span class="icon-stop" title="Terminé"></span>';
        $horaires = 'Ce transfert est <span class="nok">&nbsp;terminé&nbsp;</span> depuis le <strong> '.format_date($r['date_to'])." à ${r['heure_to']}</strong>. Les transferts de documents ne sont plus possibles.";
      }
    }
    $deadline = $r['limite'] ?: 'Pas d\'échéance';
    // Indications
    $indications = ( $r['indications'] ? "<p><strong>Indications pour les élèves&nbsp;:</strong></p>\n    <div class=\"indications\">${r['indications']}</div>" : '<p>Il n\'y a pas d\'indication spécifique visible des élèves.</p>' );
    $mathjax = $mathjax ?: boolval(strpos($r['indications'],'$')+strpos($r['indications'],'\\'));
    if ( $nbpresents )  {
      $concernes = "Ce transfert concerne actuellement <strong>$nbpresents élève".( ( $nbpresents > 1 ) ? 's' : '' ).'</strong>';
      $concernes .= ( $nbdocs != $nbpresents ) ? " ($nbdocs document".( ( $nbdocs > 1 ) ? 's' : '' ).' envoyé'.( ( $nbdocs > 1 ) ? 's' : '' ).').' : '.';
    }
    else 
      $concernes = "Aucun document n'a encore été envoyé.";
    // Sens
    $type = $r['type'];
    $encadrants = 'P'.( $type >> 1 & 1 ? 'C' : '' ).( $type >> 2 & 1 ? 'L' : '' );
    $detailsens = preg_replace('/,([^,]+)$/',' et$1',implode(', ',array_filter($comptes,function($a) use($type) { return $type >> $a & 1; },ARRAY_FILTER_USE_KEY)));
    if ( $r['envoi'] )  {
      $sens = '<span title="Des encadrants vers les élèves">&rarr;</span>';
      $detailsens = "Transfert des $detailsens vers les élèves";
      $iconevoir = ( $r['encours'] ) ? '<a class="icon-ajoutetransdocs formulaire" title="Ajouter des documents"></a>'
                                        : '<a class="icon-transrep formulaire" title="Voir tous les documents"></a>';
      if ( $nbpresents && ( $nperso = $nbdocs-$nbperso ) )
        $concernes .= " Vous avez envoyé $nperso document".( ( $nperso > 1 ) ? 's' : '' ).'.';
      $adjectifmails = 'concernés';
      $lien_dl_total = '';
    }
    else  {
      $sens = '<span title="Des élèves vers les encadrants">&larr;</span>';
      $detailsens = "Transfert des élèves vers les $detailsens";
      $iconevoir = '<a class="icon-transrep formulaire" title="Voir tous les documents"></a>';
      $adjectifmails = 'participants';
      $lien_dl_total =  ( $nbdocs ) ? "\n    <p><a class=\"icon-download\" title=\"Télécharger l'ensemble des documents\"></a> Télécharger l'ensemble des documents envoyés par les élèves</p>" : '';
    }
    // Envoi de mail 
    $mails = '';
    if ( $presents || $absents )  {
      $mails .= ( $presents ) ? "\n    <p><a class=\"icon-mail\" href=\"mail?enr_dests&uids=$presents_ids\" title=\"Envoyer un mail aux $adjectifmails\"></a>&nbsp;<strong>Élèves $adjectifmails</strong>&nbsp;: $presents</p>"
                              : "\n    <p><a class=\"icon-mail\" href=\"mail?enr_dests&uids=$presents_ids\" title=\"Envoyer un mail aux $adjectifmails\"></a>&nbsp;Envoyer un mail aux $nbpresents élèves $adjectifmails</p>";
      $mails .= ( $absents )  ? "\n    <p><a class=\"icon-mail\" href=\"mail?enr_dests&uids=$absents_ids\" title=\"Envoyer un mail aux non $adjectifmails\"></a>&nbsp;<strong>Élèves non $adjectifmails</strong>&nbsp;: $absents</p>" 
                              : "\n    <p><a class=\"icon-mail\" href=\"mail?enr_dests&uids=$absents_ids\" title=\"Envoyer un mail aux non $adjectifmails\"></a>&nbsp;Envoyer un mail aux $nbabsents élèves non $adjectifmails</p>";
    }
    // Affichages du transfert 
    $table .= "\n        <tr><td>$etat</td><td>${r['titre']}</td><td><span title=\"$detailsens\">$encadrants</span></td><td>$sens</td><td>$deadline</td><td>$nbpresents</td><td class=\"icones\" data-id=\"${r['id']}\">$iconevoir <a class=\"icon-voir\" title=\"Voir les détails du transfert\"></a></td></tr>";
    $articles .= <<< FIN

  <article class="transfert$classe" data-id="${r['id']}" data-prefixe="${r['prefixe']}" data-deadline="${r['limite']}" data-dispo="${r['dispo']}">
    <a class="icon-aide" title="Aide pour l'édition de ce transfert"></a>$iconevoir$iconesmodifier
    <h3>${r['titre']}</h3>
    <p><strong>$detailsens</strong></p>
    <p>$horaires</p>
    <p>$concernes</p>$mails$lien_dl_total
    $indications
  </article>

FIN;
  }
  $resultat->free();
  // Affichage
  echo  <<<FIN

  <article>
    <h3>Récapitulatif des transferts</h3>
    <table id="transferts">
      <thead>
        <tr><th>État</th><th>Titre</th><th>Encadrants</th><th>Sens</th><th>Date limite</th><th>Nb d'élèves</th><th></th></tr>
      </thead>
      <tbody>$table
      </tbody>
    </table>
  </article>
$articles
FIN;
}
// Pas de transfert
else  {
  if ( $requete )  
    echo "\n  <article>\n    <h3>Aucun transfert de documents ".( $mid ? "en ${matiere['nom']}" : 'sans matière associée')." ne correspond à cette recherche.</h3>\n    <p class=\"center\"><a href=\"?$cle\">Annuler la recherche</a>.</p>\n  </article>\n";
  elseif ( $autorisation == 5 )
    echo "\n  <article>\n    <h3>Vous n'avez encore organisé aucun transfert de documents ".( $mid ? "en ${matiere['nom']}" : 'sans matière associée')." cette année.</h3>\n    <p>Pour en créer un, cliquez sur l'icône <span class=\"icon-ajoute\"></span> en haut de cette page.</p>\n  </article>\n";
  else
    echo "\n  <article>\n    <h3>Aucun transfert de documents ".( $mid ? "en ${matiere['nom']}" : 'sans matière associée')." n'a encore été organisé cette année.</h3>\n    <p>Seuls les professeurs ".( $mid ? 'associés à la matière' : '')." peuvent en créer.</p>\n  </article>\n";
}

$mysqli->close();

// Taille maximale de fichier (pour l'aide)
$taille = min(ini_get('upload_max_filesize'),ini_get('post_max_size'));
if ( stristr($taille,'m') )
  $taille = substr($taille,0,-1)*1048576;
elseif ( stristr($taille,'k') )
  $taille = substr($taille,0,-1)*1024;
$taille = ( $taille < 1048576 ) ? intval($taille/1024).'&nbsp;ko' : intval($taille/1048576).'&nbsp;Mo';

// Aide et formulaire d'ajout
?>

  <script type="text/javascript">
    eids = <?php echo json_encode($eids); ?>;
    enoms = <?php echo json_encode($enoms); ?>;
  </script>

  <form id="form-edite">
    <h3 class="edition">Modifier un transfert</h3>
    <p class="ligne"><label for="titre">Titre&nbsp;: </label><input type="text" name="titre" value="" size="100"></p>
    <p class="ligne"><label for="prefixe">Préfixe&nbsp;: </label><input type="text" name="prefixe" value="" size="15"></p>
    <p class="ligne"><label for="echeance">Échéance&nbsp;: </label><input type="checkbox" class="nonbloque" name="echeance" value="1"></p>
    <p class="ligne"><label for="deadline">Date limite d'envoi&nbsp;: </label><input type="text" name="deadline" value="" size="15"></p>
    <p class="ligne"><label for="affdiff">Affichage différé&nbsp;: </label><input type="checkbox" class="nonbloque" name="affdiff" value="1"></p>
    <p class="ligne"><label for="dispo">Disponibilité &nbsp;: </label><input type="text" name="dispo" value="" size="15"></p>
    <p class="ligne"><label for="indications">Indications visibles pour les élèves&nbsp;: </label></p>
    <textarea name="indications" class="edithtml" rows="10" cols="100" placeholder="Indications visibles pour les élèves, lien vers le document que vous mettez en ligne..."></textarea>
  </form>
  
  <form id="form-ajoute" data-action="ajout-transfert">
    <h3 class="edition">Ajouter un transfert</h3>
    <p class="ligne"><label for="titre">Titre&nbsp;: </label><input type="text" name="titre" value="" size="100" placeholder="Titre du transfert"></p>
    <p class="ligne"><label for="prefixe">Préfixe&nbsp;: </label><input type="text" name="prefixe" value="" size="15" placeholder="Nom court, préfixe des noms de fichiers récupérés"></p>
    <p class="ligne"><label for="sens">Sens&nbsp;: </label><select name="sens"><option value="0">Envoi des élèves</option><option value="1">Envoi vers les élèves</option></select></p>
    <p class="ligne"><label for="accestransfert">Encadrants concernés&nbsp;: </label><select name="accestransfert[]" multiple></select></p>
    <p class="ligne"><label for="echeance">Échéance&nbsp;: </label><input type="checkbox" class="nonbloque" name="echeance" value="1"></p>
    <p class="ligne"><label for="deadline">Date limite d'envoi&nbsp;: </label><input type="text" name="deadline" value="" size="15"></p>
    <p class="ligne"><label for="affdiff">Affichage différé&nbsp;: </label><input type="checkbox" class="nonbloque" name="affdiff" value="1"></p>
    <p class="ligne"><label for="dispo">Disponibilité &nbsp;: </label><input type="text" name="dispo" value="" size="15"></p>
    <p class="ligne"><label for="indications">Indications visibles pour les élèves&nbsp;: </label></p>
    <textarea name="indications" class="edithtml" rows="10" cols="100" placeholder="Indications visibles pour les élèves, lien vers le document que vous mettez en ligne..."></textarea>
  </form>
  
  <form id="form-transrep" data-action="voir-transdocs">
    <p class="icones">
      <a class="icon-chronodesc" title="Classer par ordre chronologique inversé"></a>
      <a class="icon-chronoasc" title="Classer par ordre chronologique"></a>
      <a class="icon-alphadesc" title="Classer par ordre alphabétique inversé"></a>
      <a class="icon-alphaasc actuel" title="Classer par ordre alphabétique"></a>
      <a class="icon-actualise" title="Actualiser"></a>
    </p>
    <h3 class="edition">Détail d'un transfert</h3>
    <table>
      <thead>
        <tr>
          <th>Élève</th><th>Date</th><th>Taille</th><th>Type</th>
          <th class="icones">
            <a class="icon-download" title="Télécharger l'ensemble des documents cochés"></a>
            <a class="icon-supprime" title="Supprimer l'ensemble des documents cochés"></a>
          </th>
          <th class="icones"><a class="icon-cocher" title="Tout cocher"></a></th>
        </tr>
      </thead>
      <tbody>
      </tbody>
    </table>
  </form>

  <form id="form-ajoutetransdocs" data-action="ajout-transdocs">
    <h3 class="edition">Ajouter des documents</h3>
    <p class="ligne"><label for="fichier[]">Fichiers&nbsp;: </label><input type="file" name="fichier[]" multiple></p>
    <p class="icones">
      <a class="icon-chronodesc" title="Classer par ordre chronologique inversé"></a>
      <a class="icon-chronoasc" title="Classer par ordre chronologique"></a>
      <a class="icon-alphadesc" title="Classer par ordre alphabétique inversé"></a>
      <a class="icon-alphaasc actuel" title="Classer par ordre alphabétique"></a>
      <a class="icon-actualise" title="Actualiser"></a>
    </p>
    <p>Tous les documents seront renommés automatiquement. Les élèves les verront sous la forme «&nbsp;<em>préfixe.ext</em>&nbsp;», par exemple «&nbsp;Copie&nbsp;DM8.pdf&nbsp;». Il est donc inutile de nommer spécialement les documents sur votre ordinateur. Le préfixe est actuellement «&nbsp;<em class="prefixe"></em>&nbsp;», il est modifiable dans les réglages du transfert.</p>
    <h3>Détail du transfert</h3>
    <table>
      <thead>
        <tr>
          <th>Élève</th><th>Date</th><th>Taille</th><th>Type</th>
          <th class="icones">
            <a class="icon-download" title="Télécharger l'ensemble des documents cochés"></a>
            <a class="icon-supprime" title="Supprimer l'ensemble des documents cochés"></a>
          </th>
          <th class="icones"><a class="icon-cocher" title="Tout cocher"></a></th>
        </tr>
      </thead>
      <tbody>
      </tbody>
    </table>
  </form>

  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici d'ajouter et de modifier un transfert de documents personnels entre les élèves et les encadrants, c'est-à-dire les colleurs/comptes lycée/professeurs. Il est possible aussi de visualiser les documents envoyés, et d'en ajouter de nouveaux.</p>
    <p>La différence entre cette fonction et la fonction <em>Documents à télécharger</em> réside dans la visibilité des documents envoyés. Chaque transfert a deux propriétés d'utilisation : le sens (élèves vers encadrants ou encadrants vers élèves) et les encadrants concernés (professeurs obligatoirement, colleurs ou comptes de type lycée éventuellement). Chaque document envoyé est associé à un seul élève et n'est visible que par cet élève et l'ensemble des encadrants autorisés.</p>
    <p>Les documents transférés ne sont jamais visibles sans identification. Ils ne sont pas visibles par les moteurs de recherche.</p>
    <p>Un transfert peut être lié à une matière ou non. Le lien <span class="icon-transfert"></span> en haut du menu permet d'accéder à une liste de tous les transferts concernant l'utilisateur, sans et avec matière associée.</p>
    <p>La taille des documents envoyés simultanément est limitée à <?php echo $taille; ?>. Il n'y a pas de limite en téléchargement.</p>
    <h4>Actions générales</h4>
    <p>Les boutons généraux permettent de&nbsp;:</p>
    <ul>
      <li><span class="icon-chronodesc"></span>,<span class="icon-chronoasc"></span>,<span class="icon-alphadesc"></span>,<span class="icon-alphaasc"></span>&nbsp;: changer l'ordre de visualisation en classant soit selon le titre des transferts, soit suivant l'ordre chronologique de la date limite.</li>
      <li><span class="icon-ajoute"></span>&nbsp;: ajouter un transfert de documents (pour les professeurs seulement).</li>
    </ul>
    <h4>Possibilités de téléchargement</h4>
    <p>Les professeurs (associés à la matière à laquelle le transfert est lié si c'est le cas) sont les seuls à pouvoir créer et modifier des transferts. Ils peuvent donner accès aux colleurs et aux comptes de type lycée.</p>
    <p>Quel que soit le sens du transfert, les professeurs ont la possibilité de télécharger tous les documents. Quel que soit le sens du transfert, les élèves ne peuvent télécharger que les documents qui les concernent explicitement. Un document ne peut pas concerner deux élèves ou plus.</p>
    <p>Si le transfert est dans le sens élèves vers encadrants, l'ensemble des encadrants autorisés peuvent télécharger tous les documents envoyés par les élèves. Les documents ne sont pas fléchés vers un encadrant spécifique.</p>
    <p>Si le transfert est dans le sens encadrants vers élèves, chaque encadrant non professeur ne peut télécharger que les documents qu'il a envoyés.</p>
    <p>Si plusieurs documents sont téléchargés simultanément, ils sont regroupés dans un fichier <code>zip</code>. Ils sont systématiquement renommés pour faire apparaître le nom du transfert et le nom de l'élève. Si, dans un même transfert, plusieurs documents correspondent à un seul élève, ils sont de plus numérotés.</p>
    <h4>Date de disponibilité et date limite d'envoi</h4>
    <p>Deux dates-heures optionnelles sont définies pour chaque transfert&nbsp;:</p>
    <ul>
      <li>une <em>date de disponibilité</em> qui correspond à la date-heure à laquelle le transfert sera visible pour les élèves. Avant cette date-là, il est invisible pour eux. Mais pour tous les encadrants autorisés, le transfert n'est jamais invisible.</li>
      <li>une <em>date limite d'envoi</em> qui correspond à la date-heure jusqu'à laquelle les élèves (ou les encadrants) peuvent envoyer des documents. Une fois cette date dépassée, plus aucun nouveau document ne peut être envoyé, mais le transfert continue d'apparaître et les documents continuent d'être téléchargeables par les élèves et les encadrants concernés. Il convient de ne pas mettre de date limite pour les envois professeur vers élèves dans un premier temps, puis de la fixer quand tous les documents ont été envoyés, afin que le transfert apparaisse terminé.</li>
    </ul>
    <p>Lorsqu'elles sont définies, la date limite d'envoi est nécessairement ultérieure à la date de disponibilité.</p>
    <p>Les transferts de documents personnels non encore disponibles sont indiqués par un fond plus clair.</p>
    <h4>Tableau récapitulatif</h4>
    <p>Un tableau récapitulatif de l'ensemble des transferts associés à la matière est présenté en haut de page. Pour chaque transfert, il indique&nbsp;:</p>
    <ul>
      <li>l'<em>état</em> du transfert&nbsp;: <span class="icon-encours"></span>&nbsp;en cours, <span class="icon-stop"></span>&nbsp; terminé, <span class="icon-recent"></span> différé (non encore démarré)</li>
      <li>le <em>titre</em></li>
      <li>les <em>encadrants</em>&nbsp;: <em>P</em> pour les professeurs, <em>L</em> pour les comptes de type lycée, <em>C</em> pour les colleurs</li>
      <li>le sens du transfert&nbsp;: <em>&larr;</em> pour les transferts élèves vers encadrants, <em>&rarr;</em> pour les transferts encadrants vers élèves</li>
      <li>l'éventuelle <em>date limite</em> d'envoi</li>
      <li>le <em>nombre d'élèves</em> concernés par le transferts (ayant envoyé ou reçu des documents). Les comptes élèves qui auraient été désactivés après participation restent comptabilisés.</li>
      <li>le bouton <span class="icon-ajoutetransdocs"></span> permet d'ajouter des documents sur les transferts encadrants vers élèves, et de voir/télécharger les documents déjà envoyés</li>
      <li>le bouton <span class="icon-transrep"></span> permet de voir/télécharger les documents déjà envoyés sur les transferts élèves vers encadrants</li>
      <li>le bouton <span class="icon-voir"></span> permet de descendre automatiquement dans la page pour aller visualiser les détails du transfert sélectionné</li>
    </ul>
    <h4>Actions spécifiques à chaque transfert</h4>
    <p>Pour chaque transfert, des boutons sont disponibles afin de&nbsp;:</p>
    <ul>
      <li><span class="icon-edite"></span>&nbsp;: éditer les propriétés du transfert</li>
      <li><span class="icon-supprime"></span>&nbsp;: supprimer le transfert et tous les documents correspondants (une confirmation sera demandée)</li>
      <li><span class="icon-ajoutetransdocs"></span>&nbsp;: ajouter des documents sur les transferts encadrants vers élèves, et voir/télécharger les documents déjà envoyés</li>
      <li><span class="icon-transrep"></span>&nbsp;: voir/télécharger les documents déjà envoyés sur les transferts élèves vers encadrants</li>
    </ul>
    <p>Il est de plus possible de télécharger l'ensemble des documents transférés à l'aide du bouton <span class="icon-download"></span>.</p>
    <p>Lorsque le transfert ne concerne pas strictement tous les élèves, il est possible à l'aide de deux boutons <span class="icon-mail"></span> d'envoyer directement un courriel aux élèves concernés d'une part et aux non concernés d'autre part. Cela permet par exemple de contacter rapidement des élèves retardataires.</p>
    <p>Dans le formulaire ouvert en cliquant sur les boutons <span class="icon-ajoutetransdocs"></span> ou <span class="icon-transrep"></span>, il est possible de voir en détail chaque heure et taille de document transféré, de télécharger un document ou plusieurs documents, ou d'en supprimer.</p>
    </ul>
    <h4>Envoi de documents et affectation aux élèves</h4>
    <p>Pour les transferts dans le sens encadrants vers élèves, les documents peuvent être automatiquement affectés aux élèves si le nom du document correspond à un nom d'élève. Si ce n'est pas le cas ou si l'encadrant le souhaite, on peut modifier cette affectation en cliquant sur le nom d'élève.</p>
    <p>Chaque document envoyé peut être affecté à un ou à plusieurs élèves.</p>
    <p>Une affectation de document à un élève n'est pas modifiable après envoi.</p>
    <h4>Annonce des transferts et des documents envoyés</h4>
    <p>Aucune annonce automatique n'est faite aux élèves lorsqu'un transfert est rendu disponible, ni quand un document est envoyé dans un sens ou dans l'autre. Il est donc préférable de prévenir les élèves, par exemple par courriel, lorsque des documents personnels importants leur sont envoyés.</p>
    <h4>Lire aussi...</h4>
    <p>D'autres <span class="icon-aide"></span>&nbsp;aides, dans le cadre de chaque transfert et dans le formulaire donnant le détail des documents associés à un transfert, donne d'autres précisions sur les différentes possibilités de cette page. N'hésitez pas à les consulter&nbsp;!</p>
  </div>

  <div id="aide-transferts">
    <h3>Aide et explications</h3>
    <p>La différence entre cette fonction et la fonction <em>Documents à télécharger</em> réside dans la visibilité des documents envoyés. Chaque transfert a deux propriétés d'utilisation : le sens (élèves vers encadrants ou encadrants vers élèves) et les encadrants concernés (professeurs obligatoirement, colleurs ou comptes de type lycée éventuellement). Chaque document envoyé est associé à un seul élève et n'est visible que par cet élève et l'ensemble des encadrants autorisés.</p>
    <p>Les documents transférés ne sont jamais visibles sans identification. Ils ne sont pas visibles par les moteurs de recherche.</p>
    <p>La taille des documents envoyés simultanément est limitée à <?php echo $taille; ?>. Il n'y a pas de limite en téléchargement.</p>
    <h4>Actions spécifiques à chaque transfert</h4>
    <p>Pour chaque transfert, des boutons sont disponibles afin de&nbsp;:</p>
    <ul>
      <li><span class="icon-edite"></span>&nbsp;: éditer les propriétés du transfert</li>
      <li><span class="icon-supprime"></span>&nbsp;: supprimer le transfert et tous les documents correspondants (une confirmation sera demandée)</li>
      <li><span class="icon-ajoutetransdocs"></span>&nbsp;: ajouter des documents sur les transferts encadrants vers élèves, et voir/télécharger les documents déjà envoyés</li>
      <li><span class="icon-transrep"></span>&nbsp;: voir/télécharger les documents déjà envoyés sur les transferts élèves vers encadrants</li>
    </ul>
    <p>Il est de plus possible de télécharger l'ensemble des documents transférés à l'aide du bouton <span class="icon-download"></span>.</p>
    <p>Lorsque le transfert ne concerne pas strictement tous les élèves, il est possible à l'aide de deux boutons <span class="icon-mail"></span> d'envoyer directement un courriel aux élèves concernés d'une part et aux non concernés d'autre part. Cela permet par exemple de contacter rapidement des élèves retardataires.</p>
    <p>Dans le formulaire ouvert en cliquant sur les boutons <span class="icon-ajoutetransdocs"></span> ou <span class="icon-transrep"></span>, il est possible de voir en détail chaque heure et taille de document transféré, de télécharger un document ou plusieurs documents, ou d'en supprimer.</p>
    </ul>
    <h4>Possibilités de téléchargement</h4>
    <p>Les professeurs (associés à la matière à laquelle le transfert est lié si c'est le cas) sont les seuls à pouvoir créer et modifier des transferts. Ils peuvent donner accès aux colleurs et aux comptes de type lycée.</p>
    <p>Quel que soit le sens du transfert, les professeurs ont la possibilité de télécharger tous les documents. Quel que soit le sens du transfert, les élèves ne peuvent télécharger que les documents qui les concernent explicitement. Un document ne peut pas concerner deux élèves ou plus.</p>
    <p>Si le transfert est dans le sens élèves vers encadrants, l'ensemble des encadrants autorisés peuvent télécharger tous les documents envoyés par les élèves. Les documents ne sont pas fléchés vers un encadrant spécifique.</p>
    <p>Si le transfert est dans le sens encadrants vers élèves, chaque encadrant non professeur ne peut télécharger que les documents qu'il a envoyés.</p>
    <p>Si plusieurs documents sont téléchargés simultanément, ils sont regroupés dans un fichier <code>zip</code>. Ils sont systématiquement renommés pour faire apparaître le nom du transfert et le nom de l'élève. Si, dans un même transfert, plusieurs documents correspondent à un seul élève, ils sont de plus numérotés.</p>    
    <h4>Date de disponibilité et date limite d'envoi</h4>
    <p>Deux dates-heures optionnelles sont définies pour chaque transfert&nbsp;:</p>
    <ul>
      <li>une <em>date de disponibilité</em> qui correspond à la date-heure à laquelle le transfert sera visible pour les élèves. Avant cette date-là, il est invisible pour eux. Mais pour tous les encadrants autorisés, le transfert n'est jamais invisible.</li>
      <li>une <em>date limite d'envoi</em> qui correspond à la date-heure jusqu'à laquelle les élèves (ou les encadrants) peuvent envoyer des documents. Une fois cette date dépassée, plus aucun nouveau document ne peut être envoyé, mais le transfert continue d'apparaître et les documents continuent d'être téléchargeables par les élèves et les encadrants concernés. Il convient de ne pas mettre de date limite pour les envois professeur vers élèves dans un premier temps, puis de la fixer quand tous les documents ont été envoyés, afin que le transfert apparaisse terminé.</li>
    </ul>
    <p>Lorsqu'elles sont définies, la date limite d'envoi est nécessairement ultérieure à la date de disponibilité.</p>
    <p>Les transferts de documents personnels non encore disponibles sont indiqués par un fond plus clair.</p>
    <h4>Envoi de documents et affectation aux élèves</h4>
    <p>Pour les transferts dans le sens encadrants vers élèves, les documents peuvent être automatiquement affectés aux élèves si le nom du document correspond à un nom d'élève. Si ce n'est pas le cas ou si l'encadrant le souhaite, on peut modifier cette affectation en cliquant sur le nom d'élève.</p>
    <p>Chaque document envoyé peut être affecté à un ou à plusieurs élèves.</p>
    <p>Une affectation de document à un élève n'est pas modifiable après envoi.</p>
    <h4>Annonce des transferts et des documents envoyés</h4>
    <p>Aucune annonce automatique n'est faite aux élèves lorsqu'un transfert est rendu disponible, ni quand un document est envoyé dans un sens ou dans l'autre. Il est donc préférable de prévenir les élèves, par exemple par courriel, lorsque des documents personnels importants leur sont envoyés.</p>
  </div>

  <div id="aide-edite">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier un transfert existant. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <h4>Propriétés modifiables</h4>
    <p>Le <em>titre</em> est le titre du transfert affiché dans le cadre correspondant sur cette page. Il est de même affiché pour les élèves. Il doit être assez simple mais il vaut mieux qu'il soit différent à chaque transfert. Par exemple, «&nbsp;Devoir Maison n°9&nbsp;» ou «&nbsp;Compte-rendu de colle, semaine 12&nbsp;». Ce n'est pas la peine d'y indiquer la matière éventuelle puisqu'elle est mentionnée sur cette page.</p>
    <p>Le <em>préfixe</em> est un nom court qui servira de préfixe aux fichiers récupérés, qui seront automatiquement renommés sous la forme <code>prefixe&nbsp;-&nbsp;nom&nbsp;prénom</code>. Il vaut mieux mettre un nom court, les espaces sont autorisés. Par exemple, «&nbsp;DM9&nbsp;» ou «&nbsp;CR colle&nbsp;12&nbsp;».</p>
    <p>Cocher la case <em>Échéance</em> permet de saisir la <em>date limite d'envoi</em>, qui correspond à la date-heure jusqu'à laquelle les élèves ou les encadrants peuvent envoyer des documents, selon le sens du transfert. Elle est optionnelle. Une fois cette date dépassée, plus aucun nouveau document ne peut être envoyé, mais le transfert continue d'apparaître et les documents continuent d'être téléchargeables par les élèves et les encadrants concernés. Elle est modifiable ultérieurement. Il convient de ne pas mettre de date limite pour les envois professeur vers élèves dans un premier temps, puis de la fixer quand tous les documents ont été envoyés, afin que le transfert apparaisse terminé.</p>
    <p>Cocher la case <em>Affichage différé</em> permet de saisir la <em>date de disponibilité</em>, qui correspond à la date-heure à partir de laquelle le transfert sera visible pour les élèves. Avant cette date-là, il est invisible pour eux. Mais pour tous les encadrants autorisés, le transfert n'est jamais invisible. Valider une <em>date de disponibilité</em> vide rend le transfert visible par les élèves immédiatement.</p>
    <p>La <em>date limite d'envoi</em> est nécessairement ultérieure à la <em>date de disponibilité</em>.</p>
    <p>Les <em>indications visibles pour les élèves</em> sont les indications que l'on pout donner aux élèves. Grâce aux boutons d'édition, on peut formatter ce texte et ajouter des liens vers les autres pages du site, vers des documents du site, vers d'autres pages sur le web... L'insertion de ressources de type image, pdf ou vidéo, est aussi possible. Par exemple, on peut positionner un sujet dans les <em>Documents à télécharger</em>, avec affichage différé, et mettre le lien ici.</p>
    <p>Les <em>indications</em> ne sont pas obligatoires.</p>
    <h4>Sens de transfert non modifiable</h4>
    <p>Le sens du transfert et les encadrants concernés ont été réglés à la création du transfert et ne sont plus modifiable désormais pour ce transfert.</p>
  </div>

  <div id="aide-ajoute">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de créer un nouveau transfert de documents personnels. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>La différence entre cette fonction et la fonction <em>Documents à télécharger</em> réside dans la visibilité des documents envoyés. Chaque transfert a deux propriétés d'utilisation : le sens (élèves vers encadrants ou encadrants vers élèves) et les encadrants concernés (professeurs obligatoirement, colleurs ou comptes de type lycée éventuellement). Chaque document envoyé est associé à un seul élève et n'est visible que par cet élève et l'ensemble des encadrants autorisés.</p>
    <p>Les documents transférés ne sont jamais visibles sans identification. Ils ne sont pas visibles par les moteurs de recherche.</p>
    <p>La taille des documents envoyés simultanément est limitée à <?php echo $taille; ?>. Il n'y a pas de limite en téléchargement.</p>
    <h4>Champs à remplir</h4>
    <p>Le <em>titre</em> est le titre du transfert affiché dans le cadre correspondant sur cette page. Il est de même affiché pour les élèves. Il doit être assez simple mais il vaut mieux qu'il soit différent à chaque transfert. Par exemple, «&nbsp;Devoir Maison n°9&nbsp;» ou «&nbsp;Compte-rendu de colle, semaine 12&nbsp;». Ce n'est pas la peine d'y indiquer la matière éventuelle puisqu'elle est mentionnée sur cette page.</p>
    <p>Le <em>préfixe</em> est un nom court qui servira de préfixe aux fichiers récupérés, qui seront automatiquement renommés sous la forme <code>prefixe&nbsp;-&nbsp;nom&nbsp;prénom</code>. Il vaut mieux mettre un nom court, les espaces sont autorisés. Par exemple, «&nbsp;DM9&nbsp;» ou «&nbsp;CR colle&nbsp;12&nbsp;».</p>
    <p>Le <em>sens du transfert</em> et les <em>encadrants concernés</em> sont les deux réglages d'accès au transfert (voir les détails ci-dessous). Attention&nbsp;: ce réglage est définitif et ne peut plus être modifié pour ce transfert.</p>
    <p>Cocher la case <em>Échéance</em> permet de saisir la <em>date limite d'envoi</em>, qui correspond à la date-heure jusqu'à laquelle les élèves ou les encadrants peuvent envoyer des documents, selon le sens du transfert. Elle est optionnelle. Une fois cette date dépassée, plus aucun nouveau document ne peut être envoyé, mais le transfert continue d'apparaître et les documents continuent d'être téléchargeables par les élèves et les encadrants concernés. Elle est modifiable ultérieurement. Il convient de ne pas mettre de date limite pour les envois professeur vers élèves dans un premier temps, puis de la fixer quand tous les documents ont été envoyés, afin que le transfert apparaisse terminé.</p>
    <p>Cocher la case <em>Affichage différé</em> permet de saisir la <em>date de disponibilité</em>, qui correspond à la date-heure à partir de laquelle le transfert sera visible pour les élèves. Avant cette date-là, il est invisible pour eux. Mais pour tous les encadrants autorisés, le transfert n'est jamais invisible. Valider une <em>date de disponibilité</em> vide rend le transfert visible par les élèves immédiatement.</p>
    <p>La <em>date limite d'envoi</em> est nécessairement ultérieure à la <em>date de disponibilité</em>.</p>
    <p>Les <em>indications visibles pour les élèves</em> sont les indications que l'on pout donner aux élèves. Grâce aux boutons d'édition, on peut formatter ce texte et ajouter des liens vers les autres pages du site, vers des documents du site, vers d'autres pages sur le web... L'insertion de ressources de type image, pdf ou vidéo, est aussi possible. Par exemple, on peut positionner un sujet dans les <em>Documents à télécharger</em>, avec affichage différé, et mettre le lien ici.</p>
    <p>Les <em>indications</em> ne sont pas obligatoires.</p>
    <h4>Sens de transfert et possibilités de téléchargement</h4>
    <p>Les professeurs (associés à la matière à laquelle le transfert est lié si c'est le cas) sont les seuls à pouvoir créer et modifier des transferts. Ils peuvent donner accès aux colleurs et aux comptes de type lycée.</p>
    <p>Quel que soit le sens du transfert, les professeurs ont la possibilité de télécharger tous les documents. Quel que soit le sens du transfert, les élèves ne peuvent télécharger que les documents qui les concernent explicitement. Un document ne peut pas concerner deux élèves ou plus.</p>
    <p>Si le transfert est dans le sens élèves vers encadrants, l'ensemble des encadrants autorisés peuvent télécharger tous les documents envoyés par les élèves. Les documents ne sont pas fléchés vers un encadrant spécifique.</p>
    <p>Si le transfert est dans le sens encadrants vers élèves, chaque encadrant non professeur ne peut télécharger que les documents qu'il a envoyés.</p>
  </div>

  <div id="aide-transrep">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de voir la liste des documents envoyés sur le transfert choisi. Il ne permet pas de modifier le transfert lui-même, et se ferme en cliquant sur <span class="icon-ferme"></span>.</p>
    <p>Cette liste est classable selon l'ordre alphabétique des noms d'élèves ou l'heure d'envoi des documents à l'aide des boutons <span class="icon-chronodesc"></span>,<span class="icon-chronoasc"></span>,<span class="icon-alphadesc"></span>,<span class="icon-alphaasc"></span>.</p>
    <p>Il est possible de recharger cette liste instantanément à l'aide du bouton <span class="icon-actualise"></span>. Il n'y a pas de délai entre l'envoi du document par un élève et l'apparition dans cette liste&nbsp;: ce bouton permet immédiatement de vérifier qu'un élève dit vrai quand il assure avoir envoyé son document...</p>
    <h4>Action sur les documents</h4>
    <p>Chaque document auquel vous avez accès (voir ci-dessous) est téléchargeable avec le bouton <span class="icon-download"></span> et supprimable avec le bouton <span class="icon-supprime"></span> situés sur sa ligne. </p>
    <p>Les documents téléchargés sont systématiquement renommés pour faire apparaître le préfixe du transfert et le nom-prénom de l'élève. Si, dans un même transfert, plusieurs documents correspondent à un seul élève, ils sont de plus numérotés.</p>
    <p>Il est possible de télécharger ou supprimer plusieurs documents simultanément en les cochant et en utilisant les boutons <span class="icon-download"></span> et <span class="icon-supprime"></span> situés en haut du tableau. Les documents sont alors regroupés dans un fichier <code>zip</code>. Il est possible de cocher tous les documents en un seul clic sur le bouton <span class="icon-cocher"></span>.</p>
    <h4>Envoi de courriel</h4>
    <p>Lorsque le transfert ne concerne pas strictement tous les élèves, les élèves non présents dans le tableau sont listés. Il est possible à l'aide du bouton <span class="icon-mail"></span> d'envoyer directement un courriel aux élèves en question. Cela permet par exemple de contacter rapidement des élèves retardataires.</p>
    <h4>Possibilités de téléchargement</h4>
    <p>Les professeurs (associés à la matière à laquelle le transfert est lié si c'est le cas) sont les seuls à pouvoir créer et modifier des transferts. Ils peuvent donner accès aux colleurs et aux comptes de type lycée.</p>
    <p>Quel que soit le sens du transfert, les professeurs ont la possibilité de télécharger tous les documents. Quel que soit le sens du transfert, les élèves ne peuvent télécharger que les documents qui les concernent explicitement. Un document ne peut pas concerner deux élèves ou plus.</p>
    <p>Si le transfert est dans le sens élèves vers encadrants, l'ensemble des encadrants autorisés peuvent télécharger tous les documents envoyés par les élèves. Les documents ne sont pas fléchés vers un encadrant spécifique.</p>
    <p>Si le transfert est dans le sens encadrants vers élèves, chaque encadrant non professeur ne peut télécharger que les documents qu'il a envoyés.</p>
    <p>Il n'y a pas de limite en quantité de données pour le téléchargement.</p>
  </div>

  <div id="aide-ajoutetransdocs">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet d'envoyer simultanément plusieurs documents personnels et de visualiser, télécharger et supprimer des documents déjà envoyés. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <h4>Sélection des fichiers et taille maximale</h4>
    <p>En cliquant sur le bouton de chargement des fichiers, une fenêtre gérée par le navigateur s'ouvre. On peut y choisir autant de documents que l'on souhaite, par exemple en appuyant sur la touche <code>Ctrl</code> tout en cliquant sur les documents. Il faut que tous les documents soient dans le même dossier sur l'ordinateur. Les dossiers ne peuvent pas être envoyés.</p>
    <p>La taille maximale de l'ensemble des documents envoyés en un coup est <?php echo $taille; ?>. Il est possible de réaliser plusieurs envois si la totalité dépasse <?php echo $taille; ?> ou si tous les documents ne sont pas dans le même répertoire, ou simplement si c'est nécessaire pour des questions de temps.</p>
    <h4>Affectation des documents aux élèves</h4>
    <p>Après validation des documents choisis, pour chaque document apparaît une cases permettant l'affectation du document à un ou plusieurs élèves. Un clic sur cette case permet de réaliser ce choix.</p> 
    <p>Une proposition d'affection est automatiquement réalisée, d'après l'analyse des noms des documents. Si un nom de document comporte un nom ou un nom-prénom d'élève, l'élève est automatiquement sélectionné. Les documents non affectés automatiquement sont affichés en rouge. Dans tous les cas, l'affectation est modifiable en cliquant sur la case contenant le nom de l'élève. Les affectations sont encore modifiables jusqu'à l'envoi définitif réalisé par le bouton <span class="icon-ok"></span>.</p>
    <p>Après envoi, un document envoyé à trois élèves par exemple apparaît trois fois dans la liste des documents envoyés. Les trois documents sont indépendants et peuvent être individuellement supprimés.</p>
    <p>Une affectation de document à un élève n'est pas modifiable après envoi. En cas d'erreur, il faut supprimer le document et le renvoyer.</p>
    <p>Aucune annonce automatique n'est faite aux élèves lorsqu'un document est envoyé à un élève. Il est donc préférable de prévenir les élèves, par exemple par courriel, lorsque des documents personnels importants leur sont envoyés.</p>
    <h4>Action sur les documents</h4>
    <p>La liste des documents déjà transférés est classable selon l'ordre alphabétique des noms d'élèves ou l'heure d'envoi des documents à l'aide des boutons <span class="icon-chronodesc"></span>,<span class="icon-chronoasc"></span>,<span class="icon-alphadesc"></span>,<span class="icon-alphaasc"></span>.</p>
    <p>Il est possible de recharger cette liste instantanément à l'aide du bouton <span class="icon-actualise"></span>. Il n'y a pas de délai entre l'envoi du document par un élève et l'apparition dans cette liste&nbsp;: ce bouton permet immédiatement de vérifier qu'un élève dit vrai quand il assure avoir envoyé son document...</p>
    <p>Chaque document auquel vous avez accès (voir ci-dessous) est téléchargeable avec le bouton <span class="icon-download"></span> et supprimable avec le bouton <span class="icon-supprime"></span> situés sur sa ligne. </p>
    <p>Les documents téléchargés sont systématiquement renommés pour faire apparaître le préfixe du transfert et le nom-prénom de l'élève. Si, dans un même transfert, plusieurs documents correspondent à un seul élève, ils sont de plus numérotés.</p>
    <p>Il est possible de télécharger ou supprimer plusieurs documents simultanément en les cochant et en utilisant les boutons <span class="icon-download"></span> et <span class="icon-supprime"></span> situés en haut du tableau. Les documents sont alors regroupés dans un fichier <code>zip</code>. Il est possible de cocher tous les documents en un seul clic sur le bouton <span class="icon-cocher"></span>.</p>
    <h4>Envoi de courriel</h4>
    <p>Lorsque le transfert ne concerne pas strictement tous les élèves, les élèves non présents dans le tableau sont listés. Il est possible à l'aide du bouton <span class="icon-mail"></span> d'envoyer directement un courriel aux élèves en question. Cela permet par exemple de contacter rapidement des élèves retardataires.</p>
    <h4>Possibilités de téléchargement</h4>
    <p>Les professeurs (associés à la matière à laquelle le transfert est lié si c'est le cas) sont les seuls à pouvoir créer et modifier des transferts. Ils peuvent donner accès aux colleurs et aux comptes de type lycée.</p>
    <p>Quel que soit le sens du transfert, les professeurs ont la possibilité de télécharger tous les documents. Quel que soit le sens du transfert, les élèves ne peuvent télécharger que les documents qui les concernent explicitement. Un document ne peut pas concerner deux élèves ou plus.</p>
    <p>Si le transfert est dans le sens élèves vers encadrants, l'ensemble des encadrants autorisés peuvent télécharger tous les documents envoyés par les élèves. Les documents ne sont pas fléchés vers un encadrant spécifique.</p>
    <p>Si le transfert est dans le sens encadrants vers élèves, chaque encadrant non professeur ne peut télécharger que les documents qu'il a envoyés.</p>
    <p>Il n'y a pas de limite en quantité de données pour le téléchargement.</p>
    
    
  </div>


<?php
// Les colleurs et comptes lycée doivent pouvoir faire des actions sur les transferts existants.
fin(true,$mathjax,'client-zip',$edition ? 'datetimepicker' : '');
?>
