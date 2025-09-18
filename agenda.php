<?php
// Sécurité
define('OK',1);
// Configuration
include('config.php');
// Fonctions
include('fonctions.php');

/////////////////////////////
// Vérification de l'accès //
/////////////////////////////
$admin = $_SESSION['admin'] ?? false;
$mysqli = connectsql();
// Préférences : agenda_datemax, agenda_edition, agenda_nbmax, agenda_protection, agenda_vue
$resultat = $mysqli->query('SELECT GROUP_CONCAT(val ORDER BY nom) FROM prefs WHERE nom LIKE \'agenda%\'');
$prefsagenda = explode(',',$resultat->fetch_row()[0]);
$resultat->free();
$agenda_protection = $prefsagenda[3];
$agenda_edition = $prefsagenda[1];
$edition = acces($agenda_protection,0,'Agenda','agenda',$mysqli,$agenda_edition);
$editionjs = $donnees = $mathjax = false;
if ( $edition || $admin )  {
  $editionjs = true;
  $donnees = array('action'=>'agenda','matiere'=>0,'protection'=>$agenda_protection,'edition'=>$agenda_edition,'css'=>'datetimepicker');
}
$mode_lecture = ( $edition || $admin ) ? $_SESSION['mode_lecture'] : 0;

////////////////////////////////////////////////
// Validation de la requête : mois ou semaine //
////////////////////////////////////////////////
if ( isset($_REQUEST['mois']) && is_numeric($mois = $_REQUEST['mois']) && $mois > 1000 && $mois != date('ym') )  {
  // Début du mois
  $debut = mktime(0,0,0,$mois%100,1,intval($mois/100));
  $vue = 1;
}
elseif ( isset($_REQUEST['semaine']) && is_numeric($semaine = $_REQUEST['semaine']) && $semaine > 10000 )  {
  // Début de la semaine
  $debut = mktime(0,0,0,intval(($semaine%1000)/10),1+7*($semaine%10-1),intval($semaine/1000));
  $vue = 2;
  $nbs = 1;
}
else  {
  switch ( $_REQUEST['vue'] ?? '' )  {
    case 'mois':    $vue = 1; break;
    case 'semaine': $vue = 2; break;
    default:        $vue = $prefsagenda[4];
  }
  $debut = ( $vue == 1 ) ? mktime(0,0,0,idate('m'),1,idate('y')) : mktime(0,0,0);
}
// Jour de la semaine (0->lundi, 6->dimanche)
$jds = (idate('w',$debut)+6) % 7;
// Nb de jours dans le mois courant et dans l'éventuel mois précédent
$nj_avant = idate('t',$debut - 7*86400);
$nj = idate('t',$debut);
// Décalage initial
$premier = ( $vue == 1 ) ? 1 : max(1, idate('d',$debut) - $jds );
// Mois "principal" : indice des jours limites du mois principal
$i_avant = max(0, $jds - idate('d',$debut) + 1 );
$i_apres = max(0, $nj - $premier + $i_avant );
// Nb de semaines à afficher : 5 ou 6 si vue mensuelle, 1 ou 2 si vue hebdo
$nbs ??= ( $vue == 1 ) ? 5 + ( $nj + $jds > 35 ) : 1 + ( $jds > 3 );
// Aujoutrd'hui
$auj = intval( (mktime(0,0,0)-$debut) / 86400 ) + $i_avant;

////////////
/// HTML ///
////////////
$mois = array('','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre');
debut($mysqli,'Agenda - '.$mois[idate('m',$debut)].' '.date('Y',$debut),$message,$autorisation,'agenda',$donnees);

// Contrôles généraux, seulement en mode édition
if ( $edition || $admin )  {
  if ( $mode_lecture )
    $icones = "    <a class=\"icon-lecture mev\" title=\"Modifier le mode de lecture\"></a>\n";
  else  {
    $icones = '';
    if ( $edition )
      $icones .= "    <a class=\"icon-ajoute formulaire\" title=\"Ajouter un nouvel événement à l'agenda\"></a>\n";
    // Non autorisé aux professeurs, seulement aux admins
    if ( $admin ) // if ( ( $edition == 2 ) || $admin )
      $icones .= "    <a class=\"icon-prefs formulaire\" title=\"Modifier les préférences de l'agenda\"></a>\n";
    if ( ( $autorisation == 5 ) || $admin )
      $icones .= "    <a class=\"icon-lecture\" title=\"Modifier le mode de lecture\"></a>\n";
    $icones .= "    <a class=\"icon-aide\" title=\"Aide pour l'édition de l'agenda\"></a>\n";
  }
  echo "\n\n  <div id=\"icones\" data-action=\"page\">\n$icones  </div>\n\n";
}

/////////////////////////////////////////
// Génération de la requête à réaliser //
/////////////////////////////////////////
// Début et fin de l'affichage (de la/les semaines affichées)
$debsql = date('Y-m-d',$debut - 86400 * $jds);
$finsql = date('Y-m-d',$debut + 86400 * ( 7 * $nbs - $jds ) );
// Requête générale : on peut y ajouter des projections en remplaçant XXX, et
// des sélections en remplaçant des YYY
$requete = "SELECT a.id, m.nom AS matiere, t.nom AS type, IFNULL(m.id,0) as mid, t.id as tid, t.couleur, texte, protection,
            DATE_FORMAT(debut,'%w%Y%m%e') AS d, DATE_FORMAT(fin,'%w%Y%m%e') AS f, DATE_FORMAT(debut,'%d/%m/%Y') AS jd, DATE_FORMAT(fin,'%d/%m/%Y') AS jf,
            DATE_FORMAT(debut,'%kh%i') AS hd, DATE_FORMAT(fin,'%kh%i') AS hf, DATEDIFF(debut,'$debsql') AS njd, DATEDIFF(fin,'$debsql') AS njf, XXX
            FROM agenda AS a LEFT JOIN `agenda-types` AS t ON a.type = t.id LEFT JOIN matieres AS m ON a.matiere = m.id
            WHERE debut < '$finsql' AND fin >= '$debsql' YYY ORDER BY fin,debut";
// Cas du mode lecture enclenché : affichage simplifié, uniquement les événements
// non cachés et autorisés pour le mode, sans édition possible mais avec
// indication si le type de compte peut éditer
if ( $mode_lecture )  {
  $a = $mode_lecture - 1;
  // Si la protection de l'agenda n'empêche pas l'affichage
  if ( !$agenda_protection || $a && !( ( $agenda_protection-1 ) >> ( $a-1 ) & 1 ) )  {
    // Si mode lecture pour des comptes éditeurs, on doit tout afficher
    if ( $e = intval( $agenda_edition && ( ( $agenda_edition-1 )>>( $a-1 ) & 1 ) ) )
      echo '  <div class="annonce">Le type de compte avec lequel vous regardez cet agenda est éditeur de la page : toutes les éléments de l\'agenda sont visibles, mais pas nécessairement modifiables.</div>';
    $requete = str_replace(array('XXX','YYY'),array('edition, 0 AS editable, '.requete_edition($a).' AS editable_ml', $e ? '' : 'AND '.requete_protection($a).' AND dispo < NOW()'), $requete); 
  }
  // Ne rien afficher si mode lecture pour un utilisateur non autorisé
  else
    $requete = 'SELECT 1 WHERE 0';
}
// Cas de l'édition possible de l'agenda : affichage de toutes les événements
elseif ( $edition )
  $requete = str_replace(array('XXX','YYY'), array('edition, '. ( ( $edition == 2 ) ? '1' : requete_edition($autorisation) ).' AS editable, 0 AS editable_ml, IF(dispo>NOW(),1,0) AS affdiff, DATE_FORMAT(dispo,\'%d/%m/%Y %kh%i\') AS dispo, DATE_FORMAT(dispo,\'%w%Y%m%e\') AS dispo2',''), $requete);
// Cas de non édition de l'agenda : affichage des événements non éditables
// mais aussi récupération pour affichage avec édition des champs pour les autres
else
  $requete = str_replace(array('XXX','YYY'), array('0 as edition, '.requete_edition($autorisation).' AS editable, 0 AS editable_ml','AND '.requete_protection($autorisation).' AND dispo < NOW()'), $requete);
// Valeurs utilisables dans plusieurs contextes
$comptes = array('invités','élèves','colleurs','comptes de type lycée','professeurs (même non associés à la matière)');

/////////////////////////////////
// Récupération des événements //
/////////////////////////////////
// $ej rassemble les événements par jour, pour l'affichage dans le tableau
// $evenements rassemble les événements pour l'affichage sous le tableau
$evenements = array();
$ej = array_fill_keys(range(0,7*$nbs),array());
$resultat = $mysqli->query($requete);
if ( $resultat->num_rows )  {
  // $ev_js rassemble les événements à envoyer au script edition.js
  $ev_js = $couleurs = array();
  while ( $r = $resultat->fetch_assoc() )  {
    $id = $r['id'];
    // Événement sur un seul jour
    if ( $r['d'] == $r['f'] ) 
      $ej[$r['njd']][] = $id;
    // Événement sur plusieurs jours
    else  {
      // Enregistrement pour les jours concernés si événement sur plusieurs jours
      $ej[$njd = max(0,$r['njd'])][] = "{$id}_";
      $ej[$njf = min(7*$nbs,$r['njf'])][] = "_$id";
      for ( $i = $njd+1; $i < $njf; $i++ ) 
        $ej[$i][] = "_{$id}_";
    }
    // Enregistrement
    if ( $r['editable'] )
      $ev_js[$id] = array('tid'=>$r['tid'],'mid'=>$r['mid'],'debut'=>"${r['jd']} ${r['hd']}",'fin'=>"${r['jf']} ${r['hf']}",'jours'=>( $r['hd'] == '0h00' ));
    $evenements[$id] = $r;
    // Couleurs
    $couleurs[$r['tid']] = $r['couleur'];
    // MathJax
    $mathjax = $mathjax ?: strpos($r['texte'],'$')+strpos($r['texte'],'\\');
  }
  $resultat->free();
  // Envoi des couleurs vers JavaScript
  echo "<script>\n  \$( function() {\n";
  foreach ( $couleurs as $tid => $couleur )
    echo "    \$('.evnmt$tid').css('background-color','#$couleur');\n";
  echo "  });\n</script>\n";
  
}

/////////////////////////////////
// Affichage du tableau global //
/////////////////////////////////

// Génération de la topbarre d'avance/recul
if ( $vue == 1 )  {
  $prev = '?mois='.date('ym',$debut-1); // $debut appartient déjà au mois précédent, sauf si le 1er est un lundi
  $suiv = '?mois='.date('ym',$debut+3456000); // $debut + 40*86400
}
else  {
  // Semaine précédente : on reste dans le mois sauf si on est sur une semaine
  // contenant le 1er du mois. Alors, 
  $prev = '?semaine='.date('ym',$debut-($jds+6)*86400).ceil((idate('d',$debut-($jds+6)*86400)+5)/7);
  $suiv = '?semaine='.date('ym',$debut+(7-$jds)*86400).ceil((idate('d',$debut+(7.5-$jds)*86400)+6)/7);
}
?>

  <p id="rechercheagenda" class="topbarre">
    <a class="icon-precedent" href="<?php echo $prev; ?>" title="Plus tôt"></a>
    <a class="icon-suivant" href="<?php echo $suiv; ?>" title="Plus tard"></a>
    <select id="vue" onchange="window.location.href='agenda?vue='+(this.value == 1 ? 'mois' : 'semaine')"><?php echo str_replace("\"$vue\"","\"$vue\" selected",'<option value="1">Vue mensuelle</option><option value="2">Vue hebdomadaire</option>'); ?></select>
  </p>

  <div id="calendrier">
    <table id="semaine">
      <thead>
        <tr>
          <th>Lundi</th>
          <th>Mardi</th>
          <th>Mercredi</th>
          <th>Jeudi</th>
          <th>Vendredi</th>
          <th>Samedi</th>
          <th>Dimanche</th>
        </tr>
      </thead>
    </table>
<?php
// Une ligne par semaine
for ( $s = 0; $s < $nbs ; $s++ )  {
  // Identifiant du jour de début de ligne
  $d = $s*7;
  // Nombre maximal d'événements sur un jour de la semaine concernée
  $nmax = max(count($ej[$d]),count($ej[$d+1]),count($ej[$d+2]),count($ej[$d+3]),count($ej[$d+4]),count($ej[$d+5]),count($ej[$d+6]));
  $height = 2.5+max($nmax,3)*1.25;
  // Fond, obligatoire pour les lignes de séparation des jours
  echo <<<FIN
    <div style="height: ${height}em">
      <table class="semaine-bg" style="height: ${height}em">
        <tbody>
          <tr>

FIN;
  for ( $i = $d ; $i < $d+7 ; $i++ )
    echo ( ( $i < $i_avant ) || ( $i > $i_apres ) ) ? "            <td class=\"autremois\"></td>\n" : "            <td></td>\n";
  echo <<<FIN
          </tr>
        </tbody>
      </table>
      <table class="evenements">
        <thead>
          <tr>

FIN;
  // Numéros de jour
  
  for ( $i = $d ; $i < $d+7 ; $i++ )
    if ( $i < $i_avant )
      echo '            <th class="autremois">'.($i+$nj_avant-$i_avant+1)."</th>\n";
    elseif ( $i > $i_apres )
      echo '            <th class="autremois">'.($i-$i_apres)."</th>\n" ;
    else
      echo '            <th'.( ( $i == $auj ) ? ' id="aujourdhui"' : '').'>'.($i-$i_avant+$premier)."</th>\n" ;
  echo <<<FIN
          </tr>
        </thead>
        <tbody>

FIN;
  // Écriture de $nmax lignes d'événements
  for ( $j = 0 ; $j < $nmax ; $j++ )  {
    echo "          <tr>\n";
    $evenement_deja_commence = false;
    for ( $i = $d ; $i < $d+7 ; $i++ )  {
      // Si pas d'événement, on passe au jour suivant
      if ( empty($ej[$i]) )  {
        echo "            <td></td>\n";
        continue;
      }
      // Si on n'a pas affiché la veille un événement sur plusieurs jours
      // (sauf en début de semaine)
      if ( !$evenement_deja_commence )  {
        $classe = '';
        // Cas début de semaine
        if ( $i == $d )  {
          $id = array_shift($ej[$i]);
          // Si événement commencé la semaine précédente, on continue
          if ( $id[0] == '_' )  {
            $classe = ' evnmt_suite';
            $id = substr($id,1);
          }
        }
        // Hors début de semaine : on cherche un événement non déjà commencé
        else  {
          foreach ( $ej[$i] as $k=>$id )
            if ( $id[0] != '_' )  {
              unset($ej[$i][$k]);
              break;
            }
          // Si les seuls événements possibles sont déjà commencés, il ne
          // faut rien afficher
          if ( $id[0] == '_' )  {
            echo "            <td></td>\n";
            continue;
          }
        }
        // Si l'id se termine par '_', événement sur plusieurs jours
        if ( $id[strlen($id)-1] == '_' )  {
          $evenement_deja_commence = true;
          $classe .= ' evnmt_suivi';
          $id = substr($id,0,-1);
        }
      }
      // Si événement déjà commencé au moins la veille, qui termine ce jour
      elseif ( ( $pos = array_search("_$id",$ej[$i]) ) !== false )  {
        $evenement_deja_commence = false;
        unset($ej[$i][$pos]);
        $classe = ' evnmt_suite';
      }
      // Si événement déjà commencé au moins la veille, qui continue
      else  {
        unset($ej[$i][array_search("_${id}_",$ej[$i])]);
        $classe = ' evnmt_suite evnmt_suivi';
      }
      // Affichage
      $ev = $evenements[$id];
      $titre = ( $ev['matiere'] ) ? "${ev['matiere']} - ${ev['type']}" : $ev['type'];
      if ( !$classe && ( $ev['hd'] != '0h00' ) )
        $titre = str_replace('00','',$ev['hd'])." : $titre";
      echo "            <td data-id=\"$id\"><p class=\"evnmt evnmt{$evenements[$id]['tid']}$classe\">$titre</p></td>\n";
    }
    echo "          </tr>\n";
  }
  echo <<<FIN
        </tbody>
      </table>
    </div>

FIN;
}
echo "  </div>\n";

////////////////////////////////////////////////////
// Affichage des événements en dessous du tableau //
////////////////////////////////////////////////////
foreach ( $evenements as $id => $ev )  {
  
  // Génération de la date et du titre à afficher
  $titre = $ev['type'] . ( $ev['matiere'] ? " en ${ev['matiere']}" : '' );
  // Événement sur un seul jour
  if ( $ev['d'] == $ev['f'] )  {
    $date = 'Le '.format_date($ev['d']);
    if ( $ev['hd'] != '0h00' )
      $date .= ( $ev['hd'] == $ev['hf'] ) ? ' à '.str_replace('00','',$ev['hd']) : ' de '.str_replace('00','',$ev['hd']).' à '.str_replace('00','',$ev['hf']);
  }
  // Événement sur plusieurs jours
  else
    $date = ( $ev['hd'] == '0h00' ) ? 'Du '.format_date($ev['d']).' au '.format_date($ev['f'])
                                    : 'Du '.format_date($ev['d']).' à '.str_replace('00','',$ev['hd']).' au '.format_date($ev['f']).' à '.str_replace('00','',$ev['hf']); 
  
  // Événement éditable
  if ( $ev['editable'] )  {
    $donnees = json_encode($ev_js[$id],JSON_UNESCAPED_SLASHES);
    // Affichage avec possibilité d'édition globale : édition du texte et suppression/modification/protection
    if ( $edition )  {
      $iconeprotection = $iconeedition = '';
      $p = $ev['protection'];
      $e = $ev['edition'];
      // Icônes de modification
      if ( $p == 32 )  {
        $classe = ' class="cache"';
        $visible = '<a class="icon-montre" title="Afficher l\'événement aux utilisateurs autorisés"></a>
      <a class="icon-cache" style="display:none;" title="Rendre invisible l\'événement"></a>';
      }
      else  {
        $classe = '';
        $visible = '<a class="icon-montre" style="display:none;" title="Afficher l\'événement aux utilisateurs autorisés"></a>
      <a class="icon-cache" title="Rendre invisible l\'événement"></a>';
        // Affichage de la protection si différente de celle de l'agenda
        if ( $p != $agenda_protection )  {
          if ( $p )  {
            $texte = ( $p == 1 ) ? "tous les utilisateurs connectés"
                                   : 'les '.preg_replace('/,([^,]+)$/',' et$1',implode(', les ',array_filter($comptes,function($a) use($p) { return (32-$p)>>$a & 1; },ARRAY_FILTER_USE_KEY)));
            $iconeprotection = ( !$agenda_protection || ( $agenda_protection-1 == ( ($p-1) & ($agenda_protection-1) ) ) )
              ? "<span class=\"icon-lock affichable\" data-title=\"<strong>La protection de cet événement est plus restrictive que celle de l'agenda</strong> : en plus des professeurs, cet événement est visible uniquement par $texte.\"></span>" 
              : "<span class=\"icon-lock mev affichable\" data-title=\"<strong>La protection de cet événement est moins restrictive que celle de l'agenda</strong> : en plus des professeurs, cet événement est visible par $texte, y compris sur la page des <span class='icon-recent'></span>&nbsp;derniers contenus. Des utilisateurs n'ayant pas accès à l'agenda peuvent donc voir cet événement.\"></span>";
          }
          else
            $iconeprotection = '<span class="icon-lock mev affichable" data-title="Contrairement à l\'agenda, cet événement est visible de tous. Des utilisateurs n\'ayant pas accès à l\'agenda peuvent voir cet événement sur la page des <span class=\'icon-recent\'></span>&nbsp;derniers contenus."></span>';
        }
        // Affichage de l'accès en édition si différent de celui de la page
        // Remarque : édition ne peut pas valoir 1 ou 2.
        if ( $e != $agenda_edition )  {
          if ( $e )  {
            $texte = preg_replace('/,([^,]+)$/',' et$1',implode(', les ',array_filter($comptes,function($a) use($e) { return ($e-1)>>$a & 1; },ARRAY_FILTER_USE_KEY)));
            $iconeedition = ( $agenda_edition && ( $agenda_edition-1 == ( ($e-1) | ($agenda_edition-1) ) ) )
              ? "<span class=\"icon-edite affichable\" data-title=\"<strong>La possibilité d'édition de cet événement est restreinte par rapport à celle de l'agenda</strong> : en plus des professeurs, cet événement est éditable uniquement par les $texte.\"></span>" 
              : "<span class=\"icon-edite mev affichable\" data-title=\"<strong>La possibilité d'édition de cet événement est étendue par rapport à celle de l'agenda</strong> : en plus des professeurs, cet événement est aussi éditable par les $texte.\"></span>";
          }
          else
            $iconeedition = '<span class="icon-edite affichable" data-title="Cet événement n\'est éditable que par les professeurs."></span>';
        }
      }
      if ( $ev['affdiff'] )  {
        $classe = ' class="nodispo"';
        $recent = '<a class="icon-recent mev formulaire" title="Cet événement ne s\'affichera que le '.format_date($ev['dispo2']).' à '.substr($ev['dispo'],11).'"></a>';
      }
      else  {
        $recent = '<a class="icon-recent formulaire" title="Régler un affichage différé"></a>';
        $ev['dispo'] = 0;
      }
      $majpubli = ( ( $p == 32 ) || $ev['affdiff'] ) ? '' : 'majpubli';
      // Affichage
      echo <<<FIN

  <article$classe data-id="$id" data-protection="$p" data-edition="$e" data-dispo="${ev['dispo']}">$iconeprotection$iconeedition
    <p class="titreagenda edition" data-donnees='$donnees'>$date<br>$titre</p>
    <a class="icon-aide" title="Aide pour l'édition de cette information"></a>
    $recent
    <a class="icon-lock formulaire" title="Modifier la protection de l'événement"></a> 
    $visible
    <a class="icon-supprime" title="Supprimer cet événement"></a>
    <div class="editable edithtml $majpubli" data-champ="texte" placeholder="Texte de l'événement (obligatoire)">
${ev['texte']}
    </div>
  </article>

FIN;
    }
    // Édition possible pour cet événement 
    else  {
      $editionjs = true;
      // Affichage
      echo <<<FIN

  <article data-action="agenda" data-id="$id">
    <p class="titreagenda edition" data-donnees='$donnees'>$date<br>$titre</p>
    <a class="icon-aide" title="Aide pour l'édition de cet événement"></a>
    <div class="editable edithtml majpubli" data-champ="texte" placeholder="Texte de l'événement (obligatoire)">
${ev['texte']}
    </div>
  </article>

FIN;
    }
  }
  else  {
    // Mode lecture et information éditable : icone et valeur d'édition ($a défini précédemment)
    if ( $ev['editable_ml'] )  {
      $icone = "\n    <span class=\"icon-edite affichable\" data-title=\"Vous êtes en mode lecture et voyez l'agenda comme les ${comptes[$a]}. Cet événement est éditable par ce type d'utilisateur.\"></span>";
      $donnees = " data-edition=\"${ev['edition']}\"";
    }
    else
      $icone = $donnees = '';
    echo <<<FIN

  <article data-id="$id"$donnees>$icone
    <h3 class="titreagenda">$date</h3>
    <h4>$titre</h4>
    <p>${ev['texte']}</p>
  </article>

FIN;
  }
}

// Formulaires pour l'édition globale
if ( $edition || $admin ) {
  // Récupération des types d'événement
  $resultat = $mysqli->query('SELECT id, nom FROM `agenda-types` ORDER BY ordre');
  $select_types = '';
  //$types = array();
  if ( $resultat->num_rows )  {
    while ( $r = $resultat->fetch_assoc() )
      $select_types .= "<option value=\"${r['id']}\">${r['nom']}</option>";
    $resultat->free();
  }
  // Récupération des matières
  $resultat = $mysqli->query('SELECT id, nom FROM matieres ORDER BY ordre');
  $select_matieres = '';
  if ( $resultat->num_rows )  {
    while ( $r = $resultat->fetch_assoc() )
      $select_matieres .= "<option value=\"${r['id']}\">${r['nom']}</option>";
    $resultat->free();
  }
  // Génération du select pour la vue mensuelle/hebdomadaire
  $select_vue = str_replace("\"${prefsagenda[4]}\"", "\"${prefsagenda[4]}\" selected", '<option value="1">Mensuelle</option><option value="2">Hebdomadaire</option>');

  // Textes affichés sur les éventuelles icônes du titre
  switch ( $agenda_protection )  {
    case 0: break;
    case 32:
      echo "  <div id=\"aide-affprotection\"><strong>L'agenda n'est visible que pour les professeurs.</strong> Il n'est pas accessible aux autres utilisateurs.<br> Mais les événements précédés d'un cadenas rouge <span class=\'icon-lock mev\'></span> peuvent apparaître sur la page des <span class=\"icon-recent\"></span>&nbsp;derniers contenus.</div>\n\n";
      break;
    case 1:
      echo "  <div id=\"aide-affprotection\"><strong>L'agenda est visible par tous les utilisateurs connectés ayant saisi leur mot de passe, invisible sans connexion.</strong><br> Les icônes cadenas <span class=\"icon-lock\"></span> à gauche de chaque événement indiquent les événements qui ont une protection d'accès différente.<br> <span class=\"mev\">Le cadenas est rouge</span> si l'événement est visible sans connexion (via la page des <span class=\"icon-recent\"></span>&nbsp;derniers contenus).<br> Le cadenas est noir si l'événement n'est visible que par une partie des utilisateurs connectés.</div>\n\n";
      break;
    default:
      $p = $agenda_protection-1;
      $texte = preg_replace('/,([^,]+)$/',' et$1',implode(', les ',array_filter($comptes,function($a) use($p) { return !($p>>$a & 1); },ARRAY_FILTER_USE_KEY)));
      echo "  <div id=\"aide-affprotection\">En plus des professeurs, <strong>l'agenda est visible par les $texte.</strong><br> Les icônes cadenas <span class=\"icon-lock\"></span> à gauche de chaque événement indiquent les événements qui ont une protection d'accès différente.<br> <span class=\"mev\">Le cadenas est rouge</span> si l'événement est visible par des utilisateurs n'ayant pas accès à l'agenda (via la page des <span class=\"icon-recent\"></span>&nbsp;derniers contenus).<br> Le cadenas est noir si l'événement n'est visible que par une partie des utilisateurs ayant accès à l'agenda.</div>\n\n";
  }
  if ( $agenda_edition )  {
    $e = $agenda_edition-1;
    $texte = preg_replace('/,([^,]+)$/',' et$1',implode(', les ',array_filter($comptes,function($a) use($e) { return $e>>$a & 1; },ARRAY_FILTER_USE_KEY)));
    echo "  <div id=\"aide-affedition\">En plus des professeurs, <strong>l'agenda est éditable par les $texte.</strong><br> Ces utilisateurs peuvent ajouter ou retoucher des événements, mais pas modifier les préférences de la page.<br> Les icônes crayon <span class=\"icon-edite\"></span> à gauche de chaque événement indiquent les événements qui ont une possibilité d'édition différente.<br> <span class=\"mev\">Le crayon est rouge</span> si l'événement est éditable par des utilisateurs n'ayant pas ce droit sur l'ensemble de l'agenda.<br> Le crayon est noir si l'événement n'est éditable que par une partie des utilisateurs pouvant éditer l'agenda.</div>\n\n";
  }
?>

  <form id="form-agenda">
    <p class="ligne"><label for="tid">Type&nbsp;:</label>
      <select name="tid"><?php echo $select_types; ?></select>
      <a class="icon-edite" href="agenda-types">&nbsp;</a>
    </p>
    <p class="ligne"><label for="mid">Matière&nbsp;:</label>
      <select name="mid"><option value="0">Pas de matière</option><?php echo $select_matieres; ?></select>
    </p>
    <p class="ligne"><label for="debut">Début&nbsp;: </label><input type="text" name="debut" value="" size="15" placeholder="(obligatoire)"></p>
    <p class="ligne"><label for="fin">Fin&nbsp;: </label><input type="text" name="fin" value="" size="15" placeholder="(non obligatoire)"></p>
    <p class="ligne"><label for="jours">Date(s) seulement&nbsp;: </label><input type="checkbox" name="jours" value="1"></p>
  </form>
   
  <form id="form-ajoute" data-action="ajout-agenda">
    <h3 class="edition">Ajouter un événement</h3>
    <p class="ligne"><label for="type">Type&nbsp;:</label>
      <select name="type"><?php echo $select_types; ?></select>
      <a class="icon-edite" href="agenda-types">&nbsp;</a>
    </p>
    <p class="ligne"><label for="matiere">Matière&nbsp;:</label>
      <select name="matiere"><option value="0">Pas de matière</option><?php echo $select_matieres; ?></select>
    </p>
    <p class="ligne"><label for="debut">Début&nbsp;: </label><input type="text" name="debut" value="" size="15" placeholder="(obligatoire)"></p>
    <p class="ligne"><label for="fin">Fin&nbsp;: </label><input type="text" name="fin" value="" size="15" placeholder="(non obligatoire)"></p>
    <p class="ligne"><label for="jours">Date(s) seulement&nbsp;: </label><input type="checkbox" name="jours" value="1"></p>
    <p class="ligne"><label for="recur">Événement récurrent&nbsp;: </label><input type="checkbox" class="nonbloque" name="recur" value="1"></p>
    <p class="ligne"><label for="recur_step">Pas (en jours) de récurrence&nbsp;: </label><input type="text" name="recur_step" size="2" placeholder="7 pour répéter toutes les semaines"></p>
    <p class="ligne"><label for="recur_fin">Date finale de récurrence&nbsp;: </label><input type="text" name="recur_fin" size="10" placeholder="Date maximale"></p>
    <textarea name="texte" class="edithtml" rows="10" cols="100" placeholder="Texte associé à l'événement (non obligatoire)"></textarea>
    <p class="ligne"><label for="affdiff">Affichage différé&nbsp;: </label><input type="checkbox" class="nonbloque" name="affdiff" value="1"></p>
    <p class="ligne"><label for="dispo">Disponibilité &nbsp;: </label><input type="text" name="dispo" size="15"></p>
    <p class="ligne"><label for="index_aff">Affichable sur la page d'accueil&nbsp;: </label><input type="checkbox" name="index_aff" value="1" checked></p>
    <p class="ligne"><label for="protection">Accès&nbsp;: </label><select name="protection[]" multiple data-val32="Événement invisible"></select></p>
    <p class="ligne"><label for="edition">Édition&nbsp;: </label><select name="edition[]" multiple></select></p>
    <p>Les événements sont toujours visibles et éditables par les professeurs.</p>
    <p>Seuls les comptes ayant accès à un événement peuvent l'éditer : l'accès doit inclure l'édition. Si ce n'est pas le cas dans ce que vous réglez ci-dessus, l'édition sera automatiquement réduite pour être incluse à la fois dans l'accès à l'événement et dans l'accès à l'agenda.</p>
  </form>

  <form id="form-lock" data-action="agendalock">
    <h3 class="edition">Modifier l'accès à un événement</h3>
    <p class="ligne"><label for="protection">Accès&nbsp;: </label><select name="protection[]" multiple data-val32="Événement invisible"></select></p>
    <p class="ligne"><label for="edition">Édition&nbsp;: </label><select name="edition[]" multiple></select></p>
    <p class="ligne"><label for="index_aff">Affichable sur la page d'accueil&nbsp;: </label><input type="checkbox" name="index_aff" value="1" checked></p>
    <p>Les événements sont toujours visibles et éditables par les professeurs.</p>
    <p>Seuls les comptes ayant accès à un événement peuvent l'éditer : l'accès doit inclure l'édition. Si ce n'est pas le cas dans ce que vous réglez ci-dessus, l'édition sera automatiquement réduite pour être incluse à la fois dans l'accès à l'événement et dans l'accès à l'agenda.</p>
    <input type="button" class="ligne" value="Réinitialiser aux valeurs de la page">
  </form>

  <form id="form-recent">
    <h3 class="edition">Différer l'affichage</h3>
    <p>Vous pouvez ici programmer l'affichage de cet événement. Il ne sera alors visible que par les professeurs avant cette date, et apparaîtra après cette date pour tous les utilisateurs autorisés.</p>
    <p>Laissez cette case vide pour désactiver la fonction et rendre immédiatement visible l'événement.</p>
    <input class="ligne" type="text" name="dispo" size="15" placeholder="Date de disponibilité">
  </form>

  <form id="form-prefs" data-action="prefsglobales">
    <h3 class="edition">Modifier les préférences de l'agenda</h3>
    <p class="ligne"><label for="vue">Vue&nbsp;: </label><select name="vue"><?php echo $select_vue; ?></select></p>
    <p class="ligne"><label for="protection">Accès&nbsp;: </label><select name="protection[]" multiple data-val32="Agenda désactivé"></select></p>
    <p class="ligne"><label for="edition">Édition&nbsp;: </label><select name="edition[]" multiple></select></p>
    <p class="ligne"><label for="propagation">Propager ce choix d'accès à chaque information de la page&nbsp;: </label><input type="checkbox" id="propagation" name="propagation" value="1"></p>
    <h3 class="edition">Modifier la visibilité sur la page d'accueil</h3>
    <p class="ligne"><label for="nbmax">Nombre maximal d'événements affichés&nbsp;: </label><input type="text" name="nbmax" value="<?php echo $prefsagenda[2]; ?>" size="3"></p>
    <p class="ligne"><label for="datemax">Nombre maximal de jours affichés&nbsp;: </label><input type="text" name="datemax" value="<?php echo $prefsagenda[0]; ?>" size="3"></p>
    <h3>Modification des types d'événements</h3>
    <p>Vous pouvez modifier les types d'événements (couleur, nom, clé, affichage spécifique sur la page d'accueil).</p>
    <input onclick="location.href='agenda-types'" type="button" class="ligne" value="Modifier les types d'événements">
    <input type="hidden" name="id" value="agenda">
    <input type="hidden" name="origine" value="agenda">
  </form>
  
  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici d'ajouter un événement dans l'agenda, de modifier les événements existants ou de modifier les préférences de l'agenda.</p>
    <p>Les titres (et dates/heures) et les textes dans chaque zone indiquée par des pointillés sont modifiables individuellement, en cliquant sur les boutons <span class="icon-edite"></span> et en validant avec le bouton <span class="icon-ok"></span> qui apparaît alors.</p>
    <p>Les trois boutons généraux permettent de&nbsp;:</p>
    <ul>
      <li><span class="icon-ajoute"></span>&nbsp;: ouvrir un formulaire pour ajouter un nouvel événement.</li>
<?php if ( $admin )  { ?>
      <li><span class="icon-prefs"></span>&nbsp;: ouvrir un formulaire pour modifier les préférences globales de l'agenda.</li>
      <li><span class="icon-lecture"></span>&nbsp;: accéder à la modification du «&nbsp;mode de lecture&nbsp;», qui permet de voir le contenu de cette page comme la voit un autre type de compte, notamment pour vérifier les accès en lecture et en écriture à l'agenda et aux événements.</li>
    </ul>
    <h4>Préférences globales de l'agenda</h4>
    <p>Vous pouvez modifier&nbsp;:</p>
    <ul>
      <li>l'<em>accès</em> à l'agenda.</li>
      <li>le <em>nombre d'événements affichés sur la page d'accueil</em></li>
    </ul>
    <p>Des détails sont donnés dans l'aide du formulaire de modification.</p>
<?php }  else  { ?>
      <li><span class="icon-lecture"></span>&nbsp;: accéder à la modification du «&nbsp;mode de lecture&nbsp;», qui permet de voir le contenu de cette page comme la voit un autre type de compte, notamment pour vérifier les accès en lecture et en écriture à l'agenda et aux événements.</li>
    </ul>
<?php } ?>  
    <h4>Réglage d'accès en lecture</h4>
    <p>L'accès en lecture à global à l'agenda et particulier à chaque événement peut être protégé indépendamment. Il est modifiable en cliquant sur le bouton <span class="icon-prefs"></span> en haut à droite pour l'accès à l'agenda et sur les boutons <span class="icon-lock"></span> à droite de chaque cadre pour les événements. Trois catégories de choix existent&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: agenda ou événement visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux textes des événements. Si l'agenda est en accès public, il n'y a pas de cadenas <span class="icon-lock"></span> affiché à droite de son titre.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: agenda ou événement visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte. Si l'agenda a une telle protection, un cadenas <span class="icon-lock"></span> est alors affiché à droite de son titre. En cliquant dessus, un texte listant les comptes ayant accès en lecture à l'agenda s'affiche.</li>
      <li><em>Agenda désactivé</em>&nbsp;: agenda invisible y compris dans le menu, et événements invisibles sur la page d'accueil et sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus.</li>
      <li><em>Événement invisible</em>&nbsp;: événement entièrement invisible pour les utilisateurs autres que les utilisateurs ayant accès en écriture à l'agenda.</li>
    </ul>
    <h4>Réglage spécifique d'accès en lecture des événements</h4>
    <p>Les événements peuvent avoir le même réglage d'accès en lecture que l'agenda ou non.</p>
    <ul>
      <li>Un événement visible par tous les utilisateurs ayant accès à l'agenda n'a pas de cadenas à gauche de son titre. C'est le cas le plus classique et le réglage par défaut à la création d'un nouvel événement.</li>
      <li>Un événement visible par une partie des utilisateurs ayant accès à l'agenda est marqué par un cadenas noir <span class="icon-lock"></span> à gauche de son titre. Ceci permet par exemple, si l'agenda est visible sans identification, de positionner un événement réservé aux utilisateurs identifiés, notamment si le texte contient des noms d'élèves ou de colleurs.</li>
      <li>Un événement visible par des utilisateurs n'ayant pas accès à l'agenda est marqué par un cadenas rouge <span class="icon-lock mev"></span> à gauche de son titre. Ce réglage, a priori rarement utile, peut permettre d'ajouter un événement dans la page des <span class="icon-recent"></span>&nbsp;derniers contenus pour des utilisateurs qui ne verraient pas l'agenda.</li>
    </ul>
    <p>Un clic sur le cadenas à gauche du titre de chaque événement permet de voir le détail du réglage d'accès en lecture à l'agenda et à l'événement.</p>
    <p>Un événement peut aussi être rendu rapidement et simplement invisible des utilisateurs non éditeurs de l'agenda en cliquant sur le bouton <span class="icon-cache"></span>.</p>
    <h4>Réglage d'accès en écriture</h4>
    <p>L'accès en écriture à l'agenda et à chaque événement est modifiable comme pour la lecture. Deux catégories de choix existent&nbsp;:</p>
    <ul>
      <li><em>Professeurs uniquement</em>&nbsp;: agenda ou événement modifiable uniquement par les professeurs.</li>
      <li><em>Droits étendus à ...</em>&nbsp;: agenda ou événement modifiable par les professeurs et les comptes choisis.</li>
    </ul>
    <p>L'accès en écriture à l'agenda permet d'obtenir le bouton général d'ajout d'événement <span class="icon-ajoute"></span> ainsi que tous les boutons spécifiques à chaque événement. La modification des préférences de l'agenda reste réservée aux utilisateurs disposant des droits d'administration.</p>
    <p>Pour un utilisateur qui n'a pas accès en écriture à l'agenda, l'accès en écriture à un événement permet d'obtenir la capacité de modifier le titre (type/date/heure) et le texte de l'événement. Cela ne permet pas de modifier les réglages d'accès en lecture et écriture de l'événement.</p>
    <p>Par défaut, seuls les professeurs ont accès en écriture à l'agenda et à chaque événement. Dans ce cas, il n'y a pas de crayon <span class="icon-edite"></span> à droite du titre de la page et à gauche des titres des événements.</p>
    <p>Si d'autres utilisateurs ont accès en écriture à l'agenda, un crayon <span class="icon-edite"></span> est alors affiché à droite de son titre. En cliquant dessus, un texte listant les comptes ayant accès en écriture à l'agenda s'affiche.</p>
    <h4>Réglage spécifique d'accès en écriture des événements</h4>
    <p>Les événements peuvent avoir le même réglage d'accès en écriture que l'agenda ou non.</p>
    <ul>
      <li>Un événement éditable par tous les utilisateurs pouvant éditer l'agenda n'a pas de cadenas à gauche de son titre. C'est le cas le plus classique et le réglage par défaut à la création d'un nouvel événement.</li>
      <li>Un événement éditable par des utilisateurs n'ayant pas accès en écriture à l'agenda est marquée par un crayon rouge <span class="icon-edite mev"></span> à gauche de son titre. Ceci permet par exemple, si l'agenda est éditable uniquement par les professeurs, de positionner un événement éditable par les élèves.</li>
      <li>Un événement éditable par moins d'utilisateurs que ceux pouvant éditer l'agenda est marqué par un crayon noir <span class="icon-edite"></span> à gauche de son titre. Ceci peut permettre par exemple, si l'agenda est éditable par les colleurs, de positionner un événement qu'ils ne pourraient pas modifier ni enlever.</li>
    </ul>
    <p>Un clic sur cet éventuel crayon permet de voir le détail du réglage d'accès en écriture à l'agenda et à l'événement.</p>
    <h4>Accès minimal et imbrication des accès</h4>
    <p>Il n'est pas possible d'empêcher les professeurs de voir et d'éditer tous les événements.</p>
    <p>L'accès en écriture à l'agenda n'est possible que pour les utilisateurs ayant accès à l'agenda en lecture.</p>
    <p>L'accès en lecture à un événement n'a aucune dépendance (il ne dépend pas de l'accès en lecture à l'agenda).</p>
    <p>L'accès en écriture à un événement n'est possible que pour les utilisateurs ayant accès à l'agenda et à l'événement en lecture (il ne dépend pas de l'accès en écriture à l'agenda).</p>
    <h4>Lire aussi...</h4>
    <p>Une autre <span class="icon-aide"></span>&nbsp;aide dans le cadre de chaque événement donne d'autres précisions sur les différents boutons modifiant chaque événement. Une autre aide est aussi disponible dans chaque action. N'hésitez pas à les consulter&nbsp;!</p>
    <h4>Types d'événements</h4>
    <p>Les types d'événements ne sont pas modifiables sur cette page, mais il le sont sur une <a href="agenda-types">page spécifique</a>.</p>
  </div>

  <div id="aide-ajoute">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet d'ajouter un événement de l'agenda. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <h4>Type, matière</h4>
    <p>Le <em>type</em> d'événement est associé à un titre générique et à une couleur (utilisée dans le calendrier). Les types d'événements sont modifiables sur la page de modification des <a href="agenda-types">types d'événements</a>. Il est possible d'ajouter des types d'événements à ceux déjà définis.</p>
    <p>La <em>matière</em> n'est pas obligatoire, mais permet de l'afficher dans le titre. L'association d'un événement à une matière permet aussi de réduire son affichage&nbsp;: par exemple, les élèves non associés à la matière ne voient pas l'événement.</p>
    <h4>Horaires</h4>
    <p>Le <em>début</em> et la <em>fin</em> sont les deux horaires définissant l'événement. Plusieurs cas sont possibles&nbsp;:</p>
    <ul>
      <li>si <em>début</em> et <em>fin</em> sont identiques, alors l'événement sera simplement caractérisé par un horaire unique.</li>
      <li>si <em>début</em> et <em>fin</em> sont le même jour, alors l'événement sera caractérisé par une date et deux horaires, de début et de fin.</li>
      <li>si <em>début</em> et <em>fin</em> sont sur deux jours différents, alors l'événement apparaîtra sur le calendrier sur l'ensemble de la durée définie.</li>
    </ul>
    <p>La case à cocher <em>Date(s) seulement</em> permet de supprimer les heures. Cela peut permettre de définir un événement sur toute une journée (si <em>début</em> et <em>fin</em> sont identiques), voire sur plusieurs jours (s'ils sont différents&nbsp;; <em>fin</em> est alors inclus dans l'intervalle).</p>
    <h4>Texte optionnel</h4>
    <p>La case de saisie de texte est fournie pour ajouter une description plus longue à l'événement. Cette description n'est pas obligatoire mais sera utile la plupart du temps. En dehors du mode d'édition dans lequel vous êtes actuellement, ce texte sera affiché lors d'un clic sur l'événement, dans le calendrier.</p>
    <p>Le <em>texte</em> doit être au final formaté en HTML, mais les boutons d'édition vous aident à cela (pas besoin de s'y connaître !). Ils permettent aussi d'ajouter des liens vers les autres pages du site, vers des documents du site, vers d'autres pages sur le web... L'insertion de ressources de type image, pdf ou vidéo, est aussi possible. Le dernier de ces boutons d'édition affiche une aide sur leur fonctionnement.</p>
    <h4>Affichage et visibilité</h4>
    <p>Il est possible de choisir un <em>affichage différé</em> en cochant cette case&nbsp;: l'événement restera alors invisible jusqu'à la date-heure de <em>disponibilité</em> définie (nécessairement dans le futur), puis apparaîtra avec les réglages d'accès en lecture et en écriture qui sont choisis, simultanément sur l'agenda et sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus.</p>
    <p>Les événements en affichage différé sont indiqués par un fond plus clair et un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra l'événement.</p>
    <p>La case à cocher <em>Affichable sur la page d'accueil</em> (indépendante pour chaque événement) permet de spécifier si l'événement peut ou non apparaître sur la page d'accueil&nbsp;: il n'apparaîtra sous aucun prétexte si elle est décochée, mais pourrra apparaître s'il est proche et si d'autres critères sont respectés :</p>
    <ul>
      <li>un <em>nombre maximal d'événements affichés</em> global, réglable dans les <span class="icon-prefs"></span>&nbsp;préférences de l'agenda</li>
      <li>un <em>nombre maximal de jours affichés</em> global, réglable dans les <span class="icon-prefs"></span>&nbsp;préférences de l'agenda, qui correspond à la durée maximale sur laquelle les événements sont récupérés pour être affichés</li>
      <li>un <em>nombre maximal d'événements affichés</em> et un <em>nombre maximal de jours affichés</em> spécifiques à chaque type d'événements, modifiables sur la page de <a href="agenda-types">modification des types d'événements</a>. Ces valeurs ne peuvent logiquement pas être supérieures aux valeurs globales et sont ajustées à la baisse si besoin. On peut par exemple, pour le type «&nbsp;Devoirs surveillés&nbsp;», spécifier l'affichage au maximum d'un seul événement.</li>
    </ul>
    <h4>Réglages des accès en lecture et en écriture</h4>
    <p>Pour l'accès en lecture, trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: événement visible par tout visiteur, sans identification. Les moteurs de recherche ont accès au texte de l'événement.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: événement visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte.</li>
      <li><em>Événement invisible</em>&nbsp;: événement entièrement invisible pour les utilisateurs autres que les utilisateurs ayant accès en écriture à l'agenda.</li>
    </ul>
    <p>Pour l'accès en écriture, deux catégories de choix existent&nbsp;:</p>
    <ul>
      <li><em>Professeurs uniquement</em>&nbsp;: événement modifiable uniquement par les professeurs.</li>
      <li><em>Droits étendus à ...</em>&nbsp;: événement modifiable par les professeurs et les comptes choisis.</li>
    </ul>
    <p>Les professeurs peuvent obligatoirement voir et éditer tous les événements. On ne peut qu'ajouter d'autres types de comptes. Toutes les combinaisons d'accès ne sont pas possibles, l'accès en écriture sera réduit si nécessaire lors de la validation du formulaire selon les règles suivantes&nbsp;:</p>
    <ul>
      <li>L'accès en lecture à un événement n'a aucune dépendance (il ne dépend pas de l'accès en lecture à l'agenda).</li>
      <li>L'accès en écriture à un événement n'est possible que pour les utilisateurs ayant accès à l'agenda et à l'événement en lecture (il ne dépend pas de l'accès en écriture à l'agenda).</li>
    </ul>
    <p>Pour un événement invisible, le réglage de l'accès en écriture disparaît et devra être précisé si on lui redonne une visibilité. Cela peut être fait ultérieurement à l'aide du bouton <span class="icon-cadenas"></span> spécifique à l'événement, ou être automatiquement égalisé aux droits d'accès en écriture de l'agenda si la publication est réalisée par le bouton <span class="icon-montre"></span>.</p>
  </div>

  <div id="aide-lock">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier les réglages d'accès en lecture et en écriture à cet événement. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Pour l'accès en lecture, trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: événement visible par tout visiteur, sans identification. Les moteurs de recherche ont accès au texte de l'événement.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: événement visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte.</li>
      <li><em>Événement invisible</em>&nbsp;: événement entièrement invisible pour les utilisateurs autres que les utilisateurs ayant accès en écriture à l'agenda.</li>
    </ul>
    <p>Pour l'accès en écriture, deux catégories de choix existent&nbsp;:</p>
    <ul>
      <li><em>Professeurs uniquement</em>&nbsp;: événement modifiable uniquement par les professeurs.</li>
      <li><em>Droits étendus à ...</em>&nbsp;: événement modifiable par les professeurs et les comptes choisis.</li>
    </ul>
    <p>Les professeurs peuvent obligatoirement voir et éditer tous les événements. On ne peut qu'ajouter d'autres types de comptes. Toutes les combinaisons d'accès ne sont pas possibles, l'accès en écriture sera réduit si nécessaire lors de la validation du formulaire selon les règles suivantes&nbsp;:</p>
    <ul>
      <li>L'accès en lecture à un événement n'a aucune dépendance (il ne dépend pas de l'accès en lecture à l'agenda).</li>
      <li>L'accès en écriture à un événement n'est possible que pour les utilisateurs ayant accès à l'agenda et à l'événement en lecture (il ne dépend pas de l'accès en écriture à l'agenda).</li>
    </ul>
    <p>Pour un événement invisible, le réglage de l'accès en écriture disparaît et devra être précisé si on lui redonne une visibilité. Cela peut être fait ultérieurement en revenant ici, ou être automatiquement égalisé aux droits d'accès en écriture de l'agenda si la publication est réalisée par le bouton <span class="icon-montre"></span>.</p>
    <h4>Réglage de la visibilité sur la page d'accueil</h4>
    <p>La case à cocher <em>Affichable sur la page d'accueil</em> (indépendante pour chaque événement) permet de spécifier si l'événement peut ou non apparaître sur la page d'accueil&nbsp;: il n'apparaîtra sous aucun prétexte si elle est décochée, mais pourrra apparaître s'il est proche et si d'autres critères sont respectés :</p>
    <ul>
      <li>un <em>nombre maximal d'événements affichés</em> global, réglable dans les <span class="icon-prefs"></span>&nbsp;préférences de l'agenda</li>
      <li>un <em>nombre maximal de jours affichés</em> global, réglable dans les <span class="icon-prefs"></span>&nbsp;préférences de l'agenda, qui correspond à la durée maximale sur laquelle les événements sont récupérés pour être affichés</li>
      <li>un <em>nombre maximal d'événements affichés</em> et un <em>nombre maximal de jours affichés</em> spécifiques à chaque type d'événements, modifiables sur la page de <a href="agenda-types">modification des types d'événements</a>. Ces valeurs ne peuvent logiquement pas être supérieures aux valeurs globales et sont ajustées à la baisse si besoin. On peut par exemple, pour le type «&nbsp;Devoirs surveillés&nbsp;», spécifier l'affichage au maximum d'un seul événement.</li>
    </ul>
    <h4>Visualisation de l'accès en lecture et écriture pour chaque événement</h4>
    <p>Chaque événement peut avoir le même réglage d'accès en lecture et écriture que l'agenda ou non. Ceci est réglable directement à l'écriture d'un nouvel événement, et modifiable pour chaque événement existant.</p>
    <ul>
      <li>Un événement visible/éditable par tous les utilisateurs ayant accès à l'agenda n'a pas d'indication à gauche de son titre. C'est le cas le plus classique et le réglage par défaut à la création d'un nouvel événement.</li>
      <li>Un événement visible par une partie des utilisateurs ayant accès à l'agenda est marqué par un cadenas noir <span class="icon-lock"></span> à gauche de son titre. Ceci permet par exemple, si l'agenda est visible sans identification, de positionner un événement réservé aux utilisateurs identifiés, notamment si elle contient des noms d'élèves ou de colleurs.</li>
      <li>Un événement visible par des utilisateurs n'ayant pas accès à l'agenda est marquée par un cadenas rouge <span class="icon-lock mev"></span> à gauche de son titre. Ce réglage, a priori rarement utile, peut permettre d'ajouter un événement sur la page d'accueil ou sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus pour des utilisateurs qui ne verraient pas l'agenda.</li>
      <li>Un événement éditable par moins d'utilisateurs que ceux pouvant éditer l'agenda est marquée par un crayon noir <span class="icon-edite"></span> à gauche de son titre. Ceci peut permettre par exemple, sur l'agenda que pourraient éditer les colleurs, de positionner un événement qu'ils ne pourraient pas modifier ni enlever.</li>
      <li>Un événement éditable par des utilisateurs n'ayant pas accès en écriture à l'agenda est marquée par un crayon rouge <span class="icon-edite mev"></span> à gauche de son titre. Ceci permet par exemple, si l'agenda est éditable uniquement par les professeurs, de positionner un événement éditable par les élèves.</li>
    </ul>
    <p>Un clic sur les éventuels cadenas/crayon à gauche du titre de chaque événement permet de voir le détail du réglage correspondant à l'agenda et à l'événement.</p>
  </div>

  <div id="aide-recent">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier l'affichage différé de l'événement. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Si une date de <em>disponibilité</em> est choisie (nécessairement dans le futur), l'événement restera alors invisible jusqu'à la date-heure définie, puis apparaîtra avec les réglages d'accès en lecture et en écriture actuellement définis, simultanément ici et sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus.</p>
    <p>Il est donc impossible de définir un affichage différé pour un événement invisible. Il faut lui définir d'abord un réglage d'accès en lecture et en écriture.</p>
    <p>Valider une <em>disponibilité</em> vide annule l'affichage différé&nbsp;: l'événement est publié immédiatement avec les réglages d'accès actuellement définis.</p>
    <p>Les événements en affichage différé sont indiquée par un fond plus clair et un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra l'événement.</p>
  </div>

  <div id="aide-prefs">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier les préférences globales de l'agenda. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>. On peut y modifier&nbsp;:</p>
    <ul>
      <li>la <em>vue</em> par défaut, hebdomadaire ou mensuelle.</li>
      <li>l'<em>accès</em> en lecture et en écriture (<em>édition</em>) à l'agenda (voir ci-dessous).</li>
      <li>les réglages de visibilité sur la page d'accueil (<em>nombre d'événements affichés</em> et <em>nombre maximal de jours affichés</em>) (voir ci-dessous).</li>
    </ul>
    <h4>Réglages des accès en lecture et en écriture</h4>
    <p>Pour l'accès en lecture, trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: agenda visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux textes des événements qui ne sont pas davantage protégés.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: agenda visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte.</li>
      <li><em>Agenda désactivé</em>&nbsp;: agenda invisible y compris dans le menu, et événements invisibles sur la page d'accueil et sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus.</li>
    </ul>
    <p>Pour l'accès en écriture, deux catégories de choix existent&nbsp;:</p>
    <ul>
      <li><em>Professeurs uniquement</em>&nbsp;: ajout d'événements et modification de leur visibilité possibles uniquement pour les professeurs, valeur par défaut.</li>
      <li><em>Droits étendus à ...</em>&nbsp;: ces opérations sont autorisées pour les professeurs et les comptes choisis.</li>
    </ul>
    <p>Les professeurs peuvent obligatoirement voir et éditer les événements. On ne peut qu'ajouter d'autres types de comptes. Seuls les utilisateurs qui peuvent voir l'agenda peuvent aussi l'éditer.</p>
    <p>Quelle que soit cette valeur, seuls les professeurs et utilisateurs disposant des droits d'administration peuvent modifier les réglages de l'agenda, ici et sur la page des <a href="reglages">réglages du site</a>.</p>
    <h4>Propagation des droits d'accès</h4>
    <p>La case à cocher <em>Propager ce choix d'accès à chaque événement</em> permet de modifier les réglages d'accès individuels en lecture et en écriture de chaque événement, en les alignant avec ceux choisis pour l'agenda. Dans la liste des événements, ceux dont l'accès en lecture est différent de celui de l'agenda sont repérés par un cadenas <span class="icon-lock"></span> à gauche de leur titre, ceux dont l'accès en écriture est différent de celui de l'agenda sont repérés par un crayon <span class="icon-edite"></span>. Valider ce formulaire en cochant cette case doit supprimer toutes les différences de réglage, donc supprimer toutes les icônes à gauche des titre des événements.</p>
    <p>Si cette case n'est pas cochée, le changement de réglage d'accès à l'agenda n'a donc aucune influence directe sur la visibilité des événements, qui restent éventuellement accessibles sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus. Cela peut par contre modifier le réglage d'accès en écriture des événements. L'accès en écriture à un événement n'est possible que pour les utilisateurs ayant accès à l'agenda et à l'événement en lecture (il ne dépend pas de l'accès en écriture à l'agenda).</p>
    <h4>Réglage de la visibilité sur la page d'accueil</h4>
    <p>Les prochains événements apparaissent automatiquement sur la page d'accueil, en fonction de plusieurs critères&nbsp;:</p>
    <ul>
      <li>un <em>nombre maximal d'événements affichés</em> global, réglable ici</li>
      <li>un <em>nombre maximal de jours affichés</em> global, réglable ici, qui correspond à la durée maximale sur laquelle les événements sont récupérés pour être affichés</li>
      <li>un <em>nombre maximal d'événements affichés</em> et un <em>nombre maximal de jours affichés</em> spécifiques à chaque type d'événements, modifiable sur la page de <a href="agenda-types">modification des types d'événements</a>. Ces valeurs ne peuvent logiquement pas être supérieures aux valeurs globales et sont ajustées à la baisse si besoin. On peut par exemple, pour le type «&nbsp;Devoirs surveillés&nbsp;», spécifier l'affichage au maximum d'un seul événement.</li>
      <li>une propriété <em>Affichable sur la page d'accueil</em> spécifique à chaque événement, ajustable à l'ajout ou modifiable ultérieurement.</li>
    </ul>
    <p>La case à cocher <em>Propager ces deux valeurs à tous les types d'événements</em> permet de modifier instantanément tous les types d'événements.</p>
    <h4>Accès en lecture et écriture pour chaque événement</h4>
    <p>Chaque événement peut avoir le même réglage d'accès en lecture et écriture que l'agenda ou non. Ceci est réglable directement à l'écriture d'un nouvel événement, et modifiable pour chaque événement existant.</p>
    <ul>
      <li>Un événement visible/éditable par tous les utilisateurs ayant accès à l'agenda n'a pas d'indication à gauche de son titre. C'est le cas le plus classique et le réglage par défaut à la création d'un nouvel événement.</li>
      <li>Un événement visible par une partie des utilisateurs ayant accès à l'agenda est marqué par un cadenas noir <span class="icon-lock"></span> à gauche de son titre. Ceci permet par exemple, si l'agenda est visible sans identification, de positionner un événement réservé aux utilisateurs identifiés, notamment si elle contient des noms d'élèves ou de colleurs.</li>
      <li>Un événement visible par des utilisateurs n'ayant pas accès à l'agenda est marquée par un cadenas rouge <span class="icon-lock mev"></span> à gauche de son titre. Ce réglage, a priori rarement utile, peut permettre d'ajouter un événement sur la page d'accueil ou sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus pour des utilisateurs qui ne verraient pas l'agenda.</li>
      <li>Un événement éditable par moins d'utilisateurs que ceux pouvant éditer l'agenda est marquée par un crayon noir <span class="icon-edite"></span> à gauche de son titre. Ceci peut permettre par exemple, sur l'agenda que pourraient éditer les colleurs, de positionner un événement qu'ils ne pourraient pas modifier ni enlever.</li>
      <li>Un événement éditable par des utilisateurs n'ayant pas accès en écriture à l'agenda est marquée par un crayon rouge <span class="icon-edite mev"></span> à gauche de son titre. Ceci permet par exemple, si l'agenda est éditable uniquement par les professeurs, de positionner un événement éditable par les élèves.</li>
    </ul>
    <p>Un clic sur les éventuels cadenas/crayon à gauche du titre de chaque événement permet de voir le détail du réglage correspondant à l'agenda et à l'événement.</p>
  </div>

  <div id="aide-agenda">
    <h3>Aide et explications</h3>
    <h4>Modification du contenu</h4>
    <p>Le titre et le texte de chaque événement sont modifiables séparément, en cliquant sur le bouton <span class="icon-edite"></span> correspondant. Chaque contenu éditable est indiqué par une bordure en pointillés.</p>
    <p>Une fois la modification réalisée, elle est validée en cliquant sur le bouton <span class="icon-ok"></span>. Un clic sur le bouton <span class="icon-annule"></span> annule la modification.</p>
    <p>Le texte doit être finalement formaté en HTML, mais les boutons d'édition vous aideront à cela (pas besoin de s'y connaître !). Ils permettent aussi d'ajouter des liens vers les autres pages du site, vers des documents du site, vers d'autres pages sur le web... L'insertion de ressources de type image, pdf ou vidéo, est aussi possible. Le dernier de ces boutons d'édition affiche une aide sur leur fonctionnement.</p>
    <p>Le titre synthétise le type d'événement, la matière éventuelle, les horaires de début et de fin. Après avoir cliqué sur le bouton <span class="icon-edite"></span> correspondant, il est possible de modifier&nbsp;:</p>
    <ul>
      <li>le <em>type</em> d'événement, associé à un titre générique et à une couleur (utilisée dans le calendrier). Les types d'événements sont modifiables sur la page de modification des <a href="agenda-types">types d'événements</a>. Il est possible d'ajouter des types d'événements à ceux déjà définis.</li>
      <li>la <em>matière</em>, qui n'est pas obligatoire mais permet de l'afficher dans le titre. L'association d'un événement à une matière permet aussi de réduire son affichage&nbsp;: par exemple, les élèves non associés à la matière ne voient pas l'événement.</li>
      <li>les horaires de <em>début</em> et de <em>fin</em></li>
    </ul>
    <p>Pour les deux horaires définissant l'événement, plusieurs cas sont possibles&nbsp;:</p>
    <ul>
      <li>si <em>début</em> et <em>fin</em> sont identiques, alors l'événement sera simplement caractérisé par un horaire unique.</li>
      <li>si <em>début</em> et <em>fin</em> sont le même jour, alors l'événement sera caractérisé par une date et deux horaires, de début et de fin.</li>
      <li>si <em>début</em> et <em>fin</em> sont sur deux jours différents, alors l'événement apparaîtra sur le calendrier sur l'ensemble de la durée définie.</li>
    </ul>
    <p>La case à cocher <em>Date(s) seulement</em> permet de supprimer les heures. Cela peut permettre de définir un événement sur toute une journée (si <em>début</em> et <em>fin</em> sont identiques), voire sur plusieurs jours (s'ils sont différents&nbsp;; <em>fin</em> est alors inclus dans l'intervalle).</p>
    <h4>Autres modifications</h4>
    <p>D'autres modifications sont possibles à l'aide des boutons disponibles pour chaque événement&nbsp;:</p>
    <ul>
      <li><span class="icon-supprime"></span>&nbsp;: supprimer l'événement (une confirmation sera demandée)</li>
      <li><span class="icon-cache"></span>&nbsp;: rendre l'événement invisible. Il ne sera alors visible que par les utilisateurs ayant l'accès en écriture à l'agenda, par défaut les professeurs. Cela peut être utile pour un événement caduque que l'on souhaite conserver par exemple pour le copier plus tard, ou pour un événement dont le texte est encore à compléter.</li>
      <li><span class="icon-montre"></span>&nbsp;: afficher l'événement à tous les utilisateurs ayant accès en lecture à l'agenda</li>
      <li><span class="icon-lock"></span>&nbsp;: gérer l'accès en lecture et en écriture à l'information</li>
      <li><span class="icon-recent"></span>&nbsp;: définir une date d'affichage différé</li>
    </ul>
    <h4>Réglages des accès en lecture et en écriture</h4>
    <p>L'accès en lecture et en écriture à chaque événement peut être spécifié, indépendamment de celui à l'agenda. Trois catégories de choix sont possibles en lecture&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: événement visible par tout visiteur, sans identification. Les moteurs de recherche ont accès au texte de l'événement.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: événement visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte.</li>
      <li><em>Événement invisible</em>&nbsp;: événement entièrement invisible pour les utilisateurs autres que les utilisateurs ayant accès en écriture à l'agenda.</li>
    </ul>
    <p>Pour l'accès en écriture, deux catégories de choix existent&nbsp;:</p>
    <ul>
      <li><em>Professeurs uniquement</em>&nbsp;: événement modifiable uniquement par les professeurs.</li>
      <li><em>Droits étendus à ...</em>&nbsp;: événement modifiable par les professeurs et les comptes choisis.</li>
    </ul>
    <p>Les professeurs peuvent obligatoirement voir et éditer tous les événements. On ne peut qu'ajouter d'autres types de comptes. Toutes les combinaisons d'accès ne sont pas possibles, l'accès en écriture sera réduit si nécessaire lors de la validation du formulaire selon les règles suivantes&nbsp;:</p>
    <ul>
      <li>L'accès en lecture à un événement n'a aucune dépendance (il ne dépend pas de l'accès en lecture à l'agenda).</li>
      <li>L'accès en écriture à un événement n'est possible que pour les utilisateurs ayant accès à l'agenda et à l'événement en lecture (il ne dépend pas de l'accès en écriture à l'agenda).</li>
    </ul>
    <p>Pour un événement invisible, le réglage de l'accès en écriture disparaît et devra être précisé si on lui redonne une visibilité. Cela peut être fait ultérieurement à l'aide du bouton <span class="icon-cadenas"></span> spécifique à l'événement, ou être automatiquement égalisé aux droits d'accès en écriture de l'agenda si la publication est réalisée par le bouton <span class="icon-montre"></span>.</p>
    <p>Les boutons <span class="icon-montre"></span> et <span class="icon-cache"></span> permettent de modifier rapidement la visibilité de l'événement&nbsp;: le bouton <span class="icon-cache"></span> rend immédiatement l'événement invisible, le bouton <span class="icon-montre"></span> le fait apparaître avec les réglages d'accès en lecture et écriture de l'agenda.</p>
    <h4>Visualisation de l'accès en lecture et écriture pour chaque événement</h4>
    <p>Chaque événement peut avoir le même réglage d'accès en lecture et écriture que l'agenda ou non. Ceci est réglable directement à l'écriture d'un nouvel événement, et modifiable pour chaque événement existant.</p>
    <ul>
      <li>Un événement visible/éditable par tous les utilisateurs ayant accès à l'agenda n'a ni cadenas ni crayon à gauche de son titre. C'est le cas le plus classique et le réglage par défaut à la création d'un nouvel événement.</li>
      <li>Un événement visible par une partie des utilisateurs ayant accès à l'agenda est marqué par un cadenas noir <span class="icon-lock"></span> à gauche de son titre. Ceci permet par exemple, si l'agenda est visible sans identification, de positionner un événement réservé aux utilisateurs identifiés, notamment si elle contient des noms d'élèves ou de colleurs.</li>
      <li>Un événement visible par des utilisateurs n'ayant pas accès à l'agenda est marquée par un cadenas rouge <span class="icon-lock mev"></span> à gauche de son titre. Ce réglage, a priori rarement utile, peut permettre d'ajouter un événement sur la page d'accueil ou sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus pour des utilisateurs qui ne verraient pas l'agenda.</li>
      <li>Un événement éditable par moins d'utilisateurs que ceux pouvant éditer l'agenda est marquée par un crayon noir <span class="icon-edite"></span> à gauche de son titre. Ceci peut permettre par exemple, sur l'agenda que pourraient éditer les colleurs, de positionner un événement qu'ils ne pourraient pas modifier ni enlever.</li>
      <li>Un événement éditable par des utilisateurs n'ayant pas accès en écriture à l'agenda est marquée par un crayon rouge <span class="icon-edite mev"></span> à gauche de son titre. Ceci permet par exemple, si l'agenda est éditable uniquement par les professeurs, de positionner un événement éditable par les élèves.</li>
    </ul>
    <p>Un clic sur les éventuels cadenas/crayon à gauche du titre de chaque événement permet de voir le détail du réglage correspondant à l'agenda et à l'événement.</p>
    <h4>Affichage différé</h4>
    <p>Il est possible de différer l'affichage en cliquant sur le bouton <span class="icon-recent"></span>. L'événement reste alors invisible jusqu'à la date-heure de définie. Il apparaîtra à cette date avec les réglages d'accès en lecture et en écriture qui sont choisis, simultanément sur l'agenda et sur la page des <span class="icon-recent"></span>&nbsp;derniers contenus.</p>
    <p>Les événements en affichage différé sont indiqués par un fond plus clair et un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra l'événement.</p>
  </div>

<?php
}
fin($editionjs,$mathjax,$edition ? 'datetimepicker' : '');
?>
