<?php
// Sécurité
define('OK',1);
// Configuration
include('config.php');
// Fonctions
include('fonctions.php');

// Données globales par défaut
// MathJax désactivé par défaut
$admin = $_SESSION['admin'] ?? false;
$editionjs = $donnees = $mathjax = false;
$icones = '';

/////////////////////////////////////////////////
// Pas de matière demandée : affichage spécial //
/////////////////////////////////////////////////
$mysqli = connectsql();
// On ne sort d'ici que si une seule matière est disponible. Dans les 
// autres cas, on affiche une page contenant les différents programmes
// pour les élèves & non connectés, une page de sélection pour les autres.
if ( empty($_GET) )  {
  if ( ( $autorisation == 5 ) || $admin )  {
    $editionjs = true;
    $donnees = array('action'=>'progcolles','matiere'=>0,'protection'=>0,'edition'=>0);
    if ( $_SESSION['mode_lecture'] )  {
      $icones = "\n  <div id=\"icones\">\n    <a class=\"icon-lecture mev\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n";
      $autorisation = $_SESSION['mode_lecture'] - 1;
    }
    else 
      $icones = "\n  <div id=\"icones\">\n    <a class=\"icon-lecture\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n";
  }
  // Cas des profs et colleurs : affichage de la liste des matières si plusieurs
  // possibles, des programmes de la matière si une seule
  if ( $autorisation > 2 )  {
    $requete = "FIND_IN_SET(id,'${_SESSION['matieres']}') AND progcolles ".( ( $autorisation == 5 ) ? '< 2' : '= 1' );
    if ( strpos($_SESSION['matieres'],'c') )
      $requete .= ' OR FIND_IN_SET(id,\''. str_replace('c','',implode(',',array_filter(explode(',',$_SESSION['matieres']),function($v){return $v[0]=='c';}))) .'\') AND progcolles = 1';
    $resultat = $mysqli->query("SELECT id, cle, nom, progcolles, progcolles_protection AS protection FROM matieres WHERE $requete ORDER BY ordre");
    // Si une seule matière trouvée, réglage automatique sur cette matière
    if ( ( $n1 = $resultat->num_rows ) == 1 )  {
      $matiere = $resultat->fetch_assoc();
      $resultat->free();
      // On sortira de cette partie et on affichera la page normale : il
      // faudra exécuter la fonction acces() avec la bonne autorisation
      if ( $_SESSION['mode_lecture'] )
        $autorisation = $_SESSION['autorisation'];
    }
    // Si plusieurs matières ou aucune 
    else  {
      // Si plusieurs matières trouvées, choix à faire.
      if ( $n1 )  {
        debut($mysqli,'Programmes de colles',$message,$autorisation,'progcolles',$donnees);
        echo "$icones\n  <article>\n    <h2>Mes matières</h2>";
        $programmesvides = 0;
        while ( $r = $resultat->fetch_assoc() )  {
          if ( $r['progcolles'] )
            echo "\n    <h3 class=\"detailmatiere\"><a href=\"?${r['cle']}\">${r['nom']}</a></h3>";
          else  {
            echo "\n    <h3 class=\"detailmatiere\"><a href=\"?${r['cle']}\">${r['nom']}</a><span>(Page vide)</span></h3>";
            $programmesvides += 1;
          }
        }
        $resultat->free();
        if ( $programmesvides )
          echo "\n    <p>Les programmes de colles ".( ( $programmesvides == 1 ) ? 'd\'une matière' : "de $programmesvides matières" ).' sont vides. Vous pouvez désactiver les programmes que vous ne comptez pas utiliser dans les <a href="matieres">réglages de vos matières</a>. Cela évite les affichages non nécessaires.</p>';
        echo "\n  </article>\n";
      }
      // Vérification des autres matières pour les profs 
      if ( $autorisation == 5 )  {
        $resultat = $mysqli->query('SELECT id, cle, nom FROM matieres
                                    WHERE NOT FIND_IN_SET(id,\''. str_replace('c','',$_SESSION['matieres']) .'\') AND progcolles = 1 AND progcolles_protection <17 ORDER BY ordre');
        if ( $n2 = $resultat->num_rows )  {
          echo "\n  <article>\n    <h2>Les autres matières</h2>";
          while ( $r = $resultat->fetch_assoc() )
              echo "\n    <h3 class=\"detailmatiere\"><a href=\"?${r['cle']}\">${r['nom']}</a></h3>";
          $resultat->free();
          echo "\n  </article>\n";
          $mysqli->close();
        }
      }
      else 
        $n2 = 0;
      // Pas de matière concernée !
      if ( $n1+$n2 == 0 )  {
        debut($mysqli,'Programmes de colles','Aucun programme de colles n\'est disponible.',$autorisation,'progcolles',$donnees);
        echo $icones;
        $mysqli->close();
      }
      fin($editionjs);
    }
  }
  // Cas des élèves, invités et non connectés : affichage des programmes de colles de cette semaine dans les matières autorisées
  // Changement automatique de semaine le vendredi à minuit
  else  {
    // Récupération des matières
    if ( $autorisation )
      $resultat = $mysqli->query("SELECT GROUP_CONCAT(id) FROM matieres WHERE FIND_IN_SET(id,'${_SESSION['matieres']}') AND ".requete_protection($autorisation,'progcolles_'));
    else
      $resultat = $mysqli->query('SELECT GROUP_CONCAT(id) FROM matieres WHERE progcolles_protection < 32');
    // Si pas de matière concernée
    if ( is_null($matieres = $resultat->fetch_row()[0]) )  {
      debut($mysqli,'Programmes de colles','Aucun programme de colles n\'est disponible.',$autorisation,'progcolles',$donnees);
      $mysqli->close();
      fin();
    }
    $resultat->free();
    $resultat = $mysqli->query("SELECT s.id, DATE_FORMAT(s.debut,'%w%Y%m%e') AS debut, s.colle, v.nom AS vacances, c.texte, m.nom, m.cle, m.progcolles_protection > 0 AS protection
                                FROM semaines AS s 
                                LEFT JOIN vacances AS v ON s.vacances = v.id
                                LEFT JOIN matieres AS m ON FIND_IN_SET(m.id,'$matieres')
                                LEFT JOIN progcolles AS c ON c.semaine = s.id AND c.matiere=m.id AND cache=0 AND dispo<NOW()
                                WHERE debut > DATE_SUB(CURDATE(), INTERVAL 5 DAY) AND debut < DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                                ORDER BY s.id, m.ordre");
    // Affichage
    debut($mysqli,'Programmes de colles',$message,$autorisation,'progcolles',$donnees);
    $mysqli->close();
    if ( !$resultat->num_rows )  {
      echo "$icones\n  <article><h2>L'année est terminée... Bonnes vacances&nbsp;!</h2></article>\n";
      fin($editionjs);
    }
    echo $icones;
    $stop = $sid = false;
    while ( $r = $resultat->fetch_assoc() )  {
      // Arrêt au démarrage d'une nouvelle semaine de colle
      if ( $stop && ( $sid != $r['id'] ) )
        break;
      $colle = $r['colle']; // 0 si pas de colle, 1 si colles classiques, 2 si préparation à l'oral
      // Semaine sans colles déclarée une seule fois
      if ( !$colle && ( $sid == $r['id'] ) )
        continue;
      $debut = format_date($r['debut']);
      $sid = $r['id'];
      // Semaine de vacances
      if ( $r['vacances'] )
        echo "\n  <article>\n    <h3>".ucfirst($debut)."&nbsp;: ${r['vacances']}</h3>\n  </article>\n";
      // Semaine sans colle
      elseif ( !$colle )
        echo "\n  <article>\n    <h3>Semaine du $debut</h3>\n    <p>Il n'y a pas de colles cette semaine.</p>\n  </article>\n";
      else  {
        // Premier affichage de la semaine
        if ( !$stop )  {
          echo ( $colle == 1 ) ? "\n  <h3>Semaine du $debut</h3>\n\n" : "\n  <h3>Semaine du $debut (préparation à l'oral)</h3>\n\n";
          // Drapeau pour l'arrêt à la semaine suivante
          $stop = true;
        }
        // Si utilisateur non connecté et programme protégé, bouton de connexion
        if ( !$autorisation && $r['protection'] )
          echo "\n  <article>\n    <h3>${r['nom']}</h3>\n    <p>Ce programme de colles n'est visible que pour les utilisateurs connectés.<br>C'est par ici&nbsp;:&nbsp;<a class=\"icon-connexion\"></a></p>\n  </article>\n";
        else  {
          if ( $r['texte'] )  {
            $mathjax = $mathjax ?: boolval(strpos($r['texte'],'$')+strpos($r['texte'],'\\'));
            echo "\n  <article>\n    <h3 class=\"detailmatiere\"><a href=\"?${r['cle']}\">${r['nom']}</a></h3>\n    ${r['texte']}\n  </article>\n";
          }
          else
            echo "\n  <article>\n    <h3 class=\"detailmatiere\"><a href=\"?${r['cle']}\">${r['nom']}</a></h3>\n    <p>Le programmes de colles de cette semaine n'est pas encore défini.</p>\n  </article>\n";
        }
      }
    }
    $resultat->free();
    fin($editionjs,$mathjax);
  }
}

////////////////////////////////////////
// Validation de la requête : matière //
////////////////////////////////////////

// Recherche de la matière concernée, variable $matiere
// Si $_REQUEST['cle'] existe, on la cherche dans les matières disponibles.
// progcolles=0 : cahier de texte vide, à afficher uniquement pour les profs concernés
// progcolles=1 : cahier de texte utilisé, à afficher pour les utilisateurs associés à la matière 
// progcolles=2 : fonction désactivée
// colles_protection permet de restreindre l'accès
// La gestion de l'accès est entièrement géré par la fonction acces
if ( !isset($matiere) )  {
  $resultat = $mysqli->query('SELECT id, cle, nom, progcolles_protection AS protection FROM matieres WHERE progcolles < 2');
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
        debut($mysqli,'Programme de colles','Mauvais paramètre d\'accès à cette page.',$autorisation,'progcolles');
        $mysqli->close();
        fin();
      }
    }
  }
  // Si aucune matière présentant son programme de colles n'est enregistrée
  else  {
    debut($mysqli,'Programme de colles','Aucun programme de colles n\'est disponible.',$autorisation,'progcolles');
    $mysqli->close();
    fin();
  }
}
$mid = $matiere['id'];
$cle = $matiere['cle'];

/////////////////////////////
// Vérification de l'accès //
/////////////////////////////
// $edition vaut 2 si professeur de la matière, 0 sinon
$edition = acces($matiere['protection'],$mid,"Programme de colles - ${matiere['nom']}","progcolles?$cle",$mysqli);
if ( $edition || $admin )  {
  $editionjs = true;
  $donnees = array('action'=>'progcolles','matiere'=>$mid,'protection'=>$matiere['protection'],'edition'=>0,'css'=>'datetimepicker');
}
$mode_lecture = ( $edition || $admin ) ? $_SESSION['mode_lecture'] : 0;

//////////////////////////////////////////////////////////////////
// Validation de la requête : semaine(s) ou éléments à afficher //
//////////////////////////////////////////////////////////////////

// Récupération des semaines et du nombre de semaines
$resultat = $mysqli->query("SELECT semaines.id, DATE_FORMAT(debut,'%w%Y%m%e') AS debut, DATE_FORMAT(debut,'%y%v') AS semaine, nom AS vacances 
                            FROM semaines LEFT JOIN vacances ON semaines.vacances = vacances.id ORDER BY semaines.id");
$select_semaines = "\n      <option value=\"0\">Toute l'année</option>";
$semaines = array(0=>'');
while ( $r = $resultat->fetch_assoc() )  {
  $semaines[] = $r['semaine'];
  $select_semaines .= "\n      <option value=\"${r['id']}\">".( $r['vacances'] ?: format_date($r['debut']) ).'</option>';
}
$resultat->free();
$nmax = count($semaines);

// Recherche sur du texte
$recherche = $requete = '';
if ( $_REQUEST['recherche'] ?? '' )  {
  $recherche = htmlspecialchars($_REQUEST['recherche']);
  $n = 0;
  $nb = $nmax;
}
// Vue de tout le programme de l'année
elseif (  isset($_REQUEST['tout']) )  {
  $n = 0;
  $nb = $nmax;
}
else  {
  // Vue d'une (ou plusieurs) semaine précise
  if ( isset($_REQUEST['n']) && ctype_digit($n = $_REQUEST['n']) && ( $n > 0 ) && ( $n <= $nmax ) )
    $requete = "WHERE s.id >= $n";
  // Vue de la semaine en cours, prochaine semaine à partir du vendredi minuit
  // $n est false si non trouvé (hors année scolaire)
  elseif ( $n = array_search(date('yW', strtotime('Monday this week',time()+86400)),$semaines) )
    $requete = "WHERE s.id >= $n";
  // Première semaine si année pas encore commencée
  elseif ( date('yW') <= $semaines[1] )
    $requete = 'WHERE s.id >= '.( $n = 1 );
  // Nombre d'éléments vus par défaut : 2 en mode édition, 1 sinon
  if ( !isset($_REQUEST['nb']) || !ctype_digit($nb = $_REQUEST['nb']) || ( $nb < 1 ) )
    $nb = 2 - !$edition;
}

////////////
/// HTML ///
////////////
debut($mysqli,"Programme de colles - ${matiere['nom']}",$message,$autorisation,"progcolles?$cle",$donnees);

// Formulaire de la demande des semaines à afficher
if ( $n )
  $select_semaines = str_replace("\"$n\"","\"$n\" selected",$select_semaines);
$boutons = "
  <p id=\"recherchecolle\" class=\"topbarre\">
    <a class=\"icon-precedent\" href=\"?$cle&amp;n=".max(1,$n-1)."\" title=\"Semaine précédente\"></a>
    <a class=\"icon-suivant\" href=\"?$cle&amp;n=".min($n+1,$nmax)."\" title=\"Semaine suivante\"></a>
    <a class=\"icon-voirtout\" href=\"?$cle&amp;tout\" title=\"Voir l'ensemble du programme de colles\"></a>
    <select id=\"semaines\" onchange=\"window.location='?$cle&amp;'+((this.value>0)?'n='+this.value:'tout');\">$select_semaines
    </select>
    <span class=\"icon-recherche\" onclick=\"window.location='?$cle&amp;recherche='+$('.topbarre input').val();\"></span>
    <input type=\"text\" value=\"$recherche\" onchange=\"window.location='?$cle&amp;recherche='+this.value;\" placeholder=\"Rechercher un mot...\">
  </p>
";

// Affichage public sans édition
if ( !$edition || $mode_lecture )  {
  
  // Si mode lecture (modification de $autorisation inutile, non utilisée dans la suite)
  if ( $mode_lecture )  {
    $editionjs = true;
    echo "\n\n  <div id=\"icones\">\n    <a class=\"icon-lecture mev\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n\n";
    if ( $matiere['protection'] && ( !( $mode_lecture-1 ) || ( ( $matiere['protection']-1 ) >> ( $mode_lecture-2 ) & 1 ) ) )  {
      echo "\n  <article><h2>Cette page n'est pas autorisée pour ce type d'utilisateur.</h2></article>\n\n";
      $mysqli->close();
      fin(true);
    }
  }
  elseif ( ( $autorisation == 5 ) || $admin )
    echo "\n\n  <div id=\"icones\">\n    <a class=\"icon-lecture\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n\n";

  echo $boutons;
  if ( $n !== false )  {
    // Affichage des programmes de colles diffusés
    if ( $recherche )
      $resultat = $mysqli->query("SELECT DATE_FORMAT(s.debut,'%w%Y%m%e') AS debut, s.colle, nom AS vacances, c.texte
                                  FROM progcolles AS c LEFT JOIN semaines AS s ON c.semaine=s.id LEFT JOIN vacances ON s.vacances = vacances.id
                                  WHERE c.matiere = $mid AND c.cache = 0 AND c.texte LIKE '%".$mysqli->real_escape_string($recherche).'%\' AND dispo < NOW() ORDER BY c.semaine');
    else
      $resultat = $mysqli->query("SELECT DATE_FORMAT(s.debut,'%w%Y%m%e') AS debut, s.colle, nom AS vacances, c.texte
                                  FROM semaines AS s LEFT JOIN vacances ON s.vacances = vacances.id
                                  LEFT JOIN (SELECT texte, semaine FROM progcolles WHERE matiere = $mid AND cache = 0 AND dispo < NOW()) AS c ON c.semaine=s.id
                                  $requete ORDER BY s.id" );
    $mysqli->close();
    if ( $resultat->num_rows > 0 )  {
      $compteur = 0;
      while ( ( $compteur < $nb ) && ( $r = $resultat->fetch_assoc() ) )  {
        $mathjax = $mathjax ?: boolval(strpos($r['texte'],'$')+strpos($r['texte'],'\\'));
        $titre = ( $r['vacances'] ) ? ucfirst(format_date($r['debut']))."&nbsp;: ${r['vacances']}" : 'Semaine du '.format_date($r['debut']);
        if ( $r['colle'] )  {
          $compteur = $compteur+1;
          $texte = $r['texte'] ?: ( ( $r['colle'] == 1 ) ? '    <p>Le programme de colles de cette semaine n\'est pas défini.</p>' : '    <p>Semaine de préparation à l\'oral.</p><p>Le programme de colles de cette semaine n\'est pas défini.</p>' );
        }
        else
          $texte = ( $r['vacances'] ) ? '' : '    <p>Il n\'y a pas de colles cette semaine.</p>';
        echo <<<FIN
      
  <article>
    <h3>$titre</h3>
$texte
  </article>

FIN;
      }
      $resultat->free();
    }
    else
      echo "\n  <article><h2>Aucun résultat n'a été trouvé pour cette recherche.</h2></article>\n";
  }
  else
    echo "\n  <article><h2>L'année est terminée... Bonnes vacances&nbsp;!</h2>\n  <p><a href=\"?$cle&amp;tout\">Revoir tout le programme de l'année</a></p></article>\n";
}

// Affichage professeur éditeur
else  {
  echo <<<FIN

  <div id="icones" data-action="page">
    <a class="icon-prefs formulaire" title="Modifier les réglages des programmes de colles"></a>
    <a class="icon-lecture" title="Modifier le mode de lecture"></a>
    <a class="icon-aide" title="Aide pour les modifications des programmes de colles"></a>
  </div>
$boutons
FIN;
  if ( $n !== false )  {
    // Affichage des semaines concernées
    // Le programme est identifié par le couple semaine-matière plutôt que son identifiant propre.
    if ( $recherche )
      $resultat = $mysqli->query("SELECT DATE_FORMAT(s.debut,'%w%Y%m%e') AS debut, s.colle, nom AS vacances, c.texte, s.id, c.cache,
                                  IF(dispo>NOW(),1,0) AS affdiff, DATE_FORMAT(dispo,'%d/%m/%Y %kh%i') AS dispo, DATE_FORMAT(dispo,'%w%Y%m%e') AS dispo2 
                                  FROM progcolles AS c LEFT JOIN semaines AS s ON c.semaine=s.id LEFT JOIN vacances ON s.vacances = vacances.id
                                  WHERE c.matiere = $mid AND c.texte LIKE '%".$mysqli->real_escape_string($recherche).'%\' ORDER BY c.semaine');
    else
      $resultat = $mysqli->query("SELECT DATE_FORMAT(s.debut,'%w%Y%m%e') AS debut, s.colle, nom AS vacances, c.texte, s.id, c.cache,
                                  IF(dispo>NOW(),1,0) AS affdiff, DATE_FORMAT(dispo,'%d/%m/%Y %kh%i') AS dispo, DATE_FORMAT(dispo,'%w%Y%m%e') AS dispo2 
                                  FROM semaines AS s LEFT JOIN vacances ON s.vacances = vacances.id
                                  LEFT JOIN (SELECT texte, semaine, cache, dispo FROM progcolles WHERE matiere = $mid) AS c ON c.semaine=s.id
                                  $requete ORDER BY s.id");
    $mysqli->close();
    if ( $resultat->num_rows )  {
      $compteur = 0;
      while ( ( $compteur < $nb ) && ( $r = $resultat->fetch_assoc() ) )  {
        $mathjax = $mathjax ?: boolval(strpos($r['texte'],'$')+strpos($r['texte'],'\\'));
        $titre = ( $r['vacances'] ) ? ucfirst(format_date($r['debut']))."&nbsp;: ${r['vacances']}" : 'Semaine du '.format_date($r['debut']);
        if ( $r['colle'] )  {
          $compteur = $compteur+1;
          if ( is_null($r['texte']) )  {
            $texte = ( $r['colle'] == 1 ) ? '<p>Le programme de colles de cette semaine n\'est pas encore défini.</p>' : '<p>Semaine de préparation à l\'oral.</p><p>Le programme de colles de cette semaine n\'est pas défini.</p>';
            echo <<<FIN

  <article data-id="${r['id']}" data-dispo="0">
    <a class="icon-aide" title="Aide pour la saisie d'un nouveau programme de colles"></a>
    <a class="icon-ajoutecolle" title="Saisir ce programme de colles"></a>
    <h3 class="edition">$titre</h3>
    $texte
  </article>

FIN;
          } 
          else  {
            $visible = ( $r['cache'] ) ? '<a class="icon-montre" title="Afficher le programme de colles aux utilisateurs autorisés"></a>' : '<a class="icon-cache" title="Rendre invisible le programme de colles"></a>';
            if ( $r['affdiff'] ) {
              $classe = ' class="nodispo"';
              $recent = '<a class="icon-recent mev formulaire" title="Ce programme de colles ne s\'affichera que le '.format_date($r['dispo2']).' à '.substr($r['dispo'],11).'"></a>';
            }
            else  {
              $classe = ( $r['cache'] ) ? ' class="cache"' : '';
              $recent = '<a class="icon-recent formulaire" title="Régler un affichage différé"></a>';
              $r['dispo'] = 0;
            }
            $majpubli = ( $r['cache'] || $r['affdiff'] ) ? '' : 'majpubli';
            echo <<<FIN

  <article$classe data-id="${r['id']}" data-dispo="${r['dispo']}">
    <a class="icon-aide" title="Aide pour l'édition de ce programme de colles"></a>
    $recent
    $visible
    <a class="icon-supprime" title="Supprimer ce programme de colles"></a>
    <h3 class="edition">$titre</h3>
    <div class="editable edithtml $majpubli" data-champ="texte" placeholder="Texte du programme de colles">
${r['texte']}
    </div>
  </article>

FIN;
          }
        }
        else  {
          if ( $r['vacances'] )
            $texte = '';
          else
            $texte = ( $_SESSION['admin'] ) ? '    <p>Il n\'y a pas de colles prévues cette semaine. Les semaines de colles sont modifiables <a href="planning">sur la page de gestion du planning annuel</a>.</p>' : '    <p>Il n\'y a pas de colles prévues cette semaine.</p>';
          echo <<<FIN

  <article>
    <h3>$titre</h3>
$texte
  </article>

FIN;
        }
      }
      $resultat->free();
    }
    else
      echo "\n  <article><h2>Aucun résultat n'a été trouvé pour cette recherche.</h2></article>\n";
  }
  else
    echo "\n  <article><h2>L'année est terminée.</h2>\n  <p><a href=\"?$cle&amp;tout\">Revoir tout le programme de l'année</a></p></article>\n";

  // Aide et formulaire d'ajout
?>

  <form id="form-ajouteprogcolle">
    <textarea name="texte" class="edithtml" rows="10" cols="100" placeholder="Texte du programme de colles (obligatoire)"></textarea>
    <p class="ligne"><label for="cache">Conserver invisible&nbsp;: </label><input type="checkbox" name="cache" value="1"></p>
    <p class="ligne"><label for="affdiff">Affichage différé&nbsp;: </label><input type="checkbox" name="affdiff" value="1"></p>
    <p class="ligne"><label for="dispo">Disponibilité &nbsp;: </label><input type="text" name="dispo" size="15"></p>
</form>

  <form id="form-recent">
    <h3 class="edition">Différer l'affichage</h3>
    <p>Vous pouvez ici programmer l'affichage de ce programme de colles. Il ne sera alors visible que par les professeurs de la matière avant cette date, et apparaîtra après cette date pour tous les utilisateurs ayant accès aux programmes de colles.</p>
    <p>Laissez cette case vide pour désactiver la fonction et rendre immédiatement visible le programme de colles.</p>
    <input class="ligne" type="text" name="dispo" size="15" placeholder="Date de disponibilité">
    <input type="hidden" name="matiere" value="<?php echo  $mid; ?>">
  </form>

  <form id="form-prefs" data-action="prefsmatiere">
    <h3 class="edition">Réglages des programmes de colles en <?php echo $matiere['nom']; ?></h3>
    <p class="ligne"><label for="progcolles_protection">Accès&nbsp;: </label><select name="progcolles_protection[]" multiple data-val32="Fonction désactivée"></select></p>
    <p>Si vous désactivez ici cette fonction, vous ne pourrez plus utiliser cette page. Les programmes de colles déjà saisis ne seront pas supprimés, le choix est réversible. Vous pourrez réactiver cette fonction à la page de <a href="matieres">gestion des matières</a>.</p>
<?php if ( $admin )  { ?>
    <p>Pour modifier les semaines correspondant à un programme de colles, il faut vous rendre à la <a href="planning">gestion du planning annuel</a>.</p>
    <p>Pour modifier les utilisateurs concernés par cette matière, il faut vous rendre à la <a href="utilisateurs-matieres">gestion des associations utilisateurs-matières</a>.</p>
<?php } ?>
  </form>

  <div id="aide-page">
    <h3>Aide et explications</h3>
    <p>Il est possible ici d'ajouter et de modifier des programmes de colles. On peut également les consulter, sélectionner une semaine ou effectuer une recherche de texte.</p>
    <p>Chaque programme de colles est associé à une semaine du planning annuel. Il est impossible de déplacer un programme de colles. Seules les semaines correspondant à un programme de colles peuvent accueillir un programme. Le planning annuel est modifiable <?php echo $_SESSION['admin'] ? 'sur la page de <a href="planning">gestion du planning annuel</a>' : 'par les administrateurs du Cahier' ; ?>.</p>
    <p>Si une semaine de colles ne contient pas de programme et peut en accueillir un, il est possible d'en rajouter un en cliquant sur le bouton <span class="icon-ajoute"></span>.</p>
    <p>Les textes déjà saisis modifiables sont dans les zones indiquées par des pointillés. Ils sont modifiables en cliquant sur les boutons <span class="icon-edite"></span> et en validant avec le bouton <span class="icon-ok"></span> qui apparaît alors.</p>
    <p>Les trois boutons généraux, en haut à droite de la page, permettent de&nbsp;:</p>
    <ul>
      <li><span class="icon-prefs"></span>&nbsp;: ouvrir un formulaire pour modifier l'accès aux programmes de colles.</li>
      <li><span class="icon-lecture"></span>&nbsp;: accéder à la modification du «&nbsp;mode de lecture&nbsp;», qui permet de voir le contenu de cette page comme la voit un autre type de compte, notamment pour vérifier l'accès en lecture à cette page.</li>
    </ul>
    <h4>Gestion de l'accès</h4>
    <p>L'accès aux programmes de colles peut être protégé en cliquant sur le bouton des préférences <span class="icon-prefs"></span>. Trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: programme visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux textes des programmes. Une page en accès public n'a pas de cadenas <span class="icon-lock"></span> affiché à droite de son titre.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: programme visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte et des matières auxquelles ils sont associés. Un cadenas <span class="icon-lock"></span> est alors affiché à droite du titre de la page. Les associations entre utilisateurs et matières sont modifiables <?php echo $_SESSION['admin'] ? 'sur la page de <a href="utilisateurs-matieres">gestion utilisateurs-matieres</a>' : 'par les administrateurs du Cahier' ; ?>.</li>
      <li><em>Fonction désactivée</em>&nbsp;: les programmes de colles pour cette matière sont complètement indisponibles. La fonction n'apparaît plus dans le menu, y compris pour vous.</li>
    </ul>
    <p>Si vous désactivez ici cette fonction, vous ne pourrez plus utiliser cette page. Les programmes de colles ne seront pas supprimés, le choix est réversible. Vous pourrez réactiver cette fonction à la page de <a href="matieres">gestion des matières</a>.</p>
    <p>Le lien vers cette page dans le menu est visible avant identification, uniquement s'il y a des programmes à afficher. Il disparaît après identification pour les utilisateurs n'ayant pas accès à cette page.</p>
    <h4>Suppression de tous les programmes de colles</h4>
    <p>L'ensemble des programmes de colles est supprimable en un clic à la page de <a href="matieres">gestion des matières</a>.</p>
    <h4>Affichage différé</h4>
    <p>Il est possible de différer l'affichage d'un programme de colles en cliquant sur le bouton <span class="icon-recent"></span>. Le programme reste alors invisible jusqu'à la date-heure définie, puis apparaîtra simultanément sur cette page et sur la page des derniers contenus (lien <span class="icon-recent"></span> du menu), pour les utilisateurs autorisés.</p>
    <p>Les programmes en affichage différé sont indiqués par un fond plus clair et un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra le programme.</p>
    <h4>Impression/récupération</h4>
    <p>Les programmes de colles, individuellement ou tous ensemble, sont très facilement imprimables à partir de la fenêtre qui apparaît en cliquant sur le bouton <span class="icon-lecture"></span> du menu ou avec la commande d'impression de votre navigateur.</p>
    <p>Dans les deux cas, tout ce que ne correspond pas aux programmes de colles (menu de gauche, marquages en pointillés, icônes de modification) disparaît à l'impression.</p>
    <p>Passer par le bouton <span class="icon-lecture"></span> permet de modifier temporairement le titre, utile pour ajouter l'année ou la classe.</p>
    <p>Le document produit, que vous pourrez conserver en papier ou en pdf, peut constituer un document utilisable pour vous ou pour une inspection.</p>
    <p>Les programmes de colles ne sont pas récupérables facilement en fichier éditable. La commande «&nbsp;enregistrer sous&nbsp;» de votre navigateur ne donnera pas de très bon résultats.</p>
    <h4>Lire aussi...</h4>
    <p>Une autre <span class="icon-aide"></span>&nbsp;aide dans le cadre de chaque programme de colles donne d'autres précisions sur les différents boutons modifiant chaque information. Une autre aide est aussi disponible dans chaque action. N'hésitez pas à les consulter&nbsp;!</p>
  </div>

  <div id="aide-progcolles">
    <h3>Aide et explications</h3>
    <h4>Modification du contenu</h4>
    <p>Le texte de chaque programme de colles existant est modifiable, en cliquant sur le bouton <span class="icon-edite"></span>. Si aucun programme de colles n'existe pour la semaine concernée, il est possible d'en créer un en cliquant sur le bouton <span class="icon-ajoute"></span>. Chaque contenu éditable est indiqué par une bordure en pointillés.</p>
    <p>Une fois le texte édité, il est validé en appuyant sur le bouton <span class="icon-ok"></span>. Au contraire, un clic sur le bouton <span class="icon-annule"></span> annule l'édition.</p>
    <p>Le texte doit être finalement formaté en HTML, mais les boutons d'édition vous aideront à cela (pas besoin de s'y connaître !). Ils permettent aussi d'ajouter des liens vers les autres pages du site, vers des documents du site, vers d'autres pages sur le web... L'insertion de ressources de type image, pdf ou vidéo, est aussi possible. Le dernier de ces boutons d'édition affiche une aide sur leur fonctionnement.</p>
    <h4>Autres modifications</h4>
    <p>D'autres modifications sont possibles à l'aide des boutons disponibles pour chaque programme de colles&nbsp;:</p>
    <ul>
      <li><span class="icon-supprime"></span>&nbsp;: supprimer le programme de colles (une confirmation sera demandée)</li>
      <li><span class="icon-cache"></span>&nbsp;: rendre le programme de colles invisible. Il ne sera alors visible que par les professeurs associés à la matière. Cela peut être utile pour entrer ses programmes de colles à l'avance ou si l'on se rend compte d'une grosse erreur que l'on souhaite corriger à l'abri des regards.</li>
      <li><span class="icon-montre"></span>&nbsp;: afficher le programme de colles à tous les utilisateurs autorisés</li>
      <li><span class="icon-recent"></span>&nbsp;: définir une date d'affichage différé</li>
    </ul>
    <h4>Affichage différé</h4>
    <p>Il est possible de différer l'affichage d'un programme de colles en cliquant sur le bouton <span class="icon-recent"></span>. Le programme reste alors invisible jusqu'à la date-heure définie, puis apparaîtra simultanément sur cette page et sur la page des derniers contenus (lien <span class="icon-recent"></span> du menu), pour les utilisateurs autorisés.</p>
    <p>Les programmes de colles en affichage différé sont indiqués par un fond plus clair et un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra le programme.</p>
  </div>

  <div id="aide-ajoute">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de créer un nouveau programme de colles. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-annule"></span>.</p>
    <p>Le texte doit être finalement formaté en HTML, mais les boutons d'édition vous aideront à cela (pas besoin de s'y connaître !). Ils permettent aussi d'ajouter des liens vers les autres pages du site, vers des documents du site, vers d'autres pages sur le web... L'insertion de ressources de type image, pdf ou vidéo, est aussi possible. Le dernier de ces boutons d'édition affiche une aide sur leur fonctionnement.</p>
    <p>La case à cocher <em>Conserver invisible</em> permet de garder temporairement invisible ce programme de colles, par exemple pour le diffuser ultérieurement. Les programmes de colles invisibles n'apparaissent qu'aux professeurs associés à la matière et sont indiqués par un fond gris clair. Ils peuvent être plus tard affichés aux utilisateurs autorisés à l'aide du bouton <span class="icon-montre"></span> correspondant au programme.</p>
    <p>Il est possible de choisir un <em>affichage différé</em> en cochant cette case&nbsp;: le programme reste alors invisible jusqu'à la date-heure définie, puis apparaîtra simultanément sur cette page et sur la page des derniers contenus (lien <span class="icon-recent"></span> du menu), pour les utilisateurs autorisés.</p>
    <p>Les programmes de colles en affichage différé sont indiqués par un fond plus clair et un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra le programme.</p>
  </div>

  <div id="aide-prefs">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier l'accès aux programmes de colles en <?php echo $matiere['nom']; ?>. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Trois catégories de choix sont possibles&nbsp;:</p>
    <ul>
      <li><em>Accès public</em>&nbsp;: programme visible par tout visiteur, sans identification. Les moteurs de recherche ont accès aux textes des programmes. Une page en accès public n'a pas de cadenas <span class="icon-lock"></span> affiché à droite de son titre.</li>
      <li><em>Utilisateurs identifiés</em>&nbsp;: programme visible uniquement par les utilisateurs identifiés à déterminer, en fonction de leur type de compte et des matières auxquelles ils sont associés. Un cadenas <span class="icon-lock"></span> est alors affiché à droite du titre de la page. Les associations entre utilisateurs et matières sont modifiables <?php echo $_SESSION['admin'] ? 'sur la page de <a href="utilisateurs-matieres">gestion utilisateurs-matieres</a>' : 'par les administrateurs du Cahier' ; ?>.</li>
      <li><em>Fonction désactivée</em>&nbsp;: les programmes de colles pour cette matière sont complètement indisponibles. La fonction n'apparaît plus dans le menu, y compris pour vous.</li>
    </ul>
    <p>Si vous désactivez ici cette fonction, vous ne pourrez plus utiliser cette page. Les programmes de colles ne seront pas supprimés, le choix est réversible. Vous pourrez réactiver cette fonction à la page de <a href="matieres">gestion des matières</a>.</p>
    <p>Le lien vers cette page dans le menu est visible avant identification, uniquement s'il y a des programmes à afficher. Il disparaît après identification pour les utilisateurs n'ayant pas accès à cette page.</p>
    <h4>Suppression de tous les programmes de colles</h4>
    <p>L'ensemble des programmes de colles est supprimable en un clic à la page de <a href="matieres">gestion des matières</a>.</p>
  </div>

  <div id="aide-recent">
    <h3>Aide et explications</h3>
    <p>Ce formulaire permet de modifier l'affichage différé du programme de colles. Il sera validé par un clic sur <span class="icon-ok"></span>, et abandonné (donc supprimé) par un clic sur <span class="icon-ferme"></span>.</p>
    <p>Si une date de <em>disponibilité</em> est choisie (nécessairement dans le futur), le programme restera alors invisible jusqu'à la date-heure définie, puis apparaîtra simultanément sur la page et sur la page des derniers contenus (lien <span class="icon-recent"></span> du menu), pour les utilisateurs autorisés.</p>
    <p>Valider une <em>disponibilité</em> vide annule l'affichage différé&nbsp;: le programme est publié immédiatement.</p>
    <p>Les programmes de colles en affichage différé sont indiqués par un fond plus clair et un bouton horloge rouge <span class="icon-recent mev"></span>. Un survol à la souris de ce bouton permet de voir à quelle heure apparaîtra le programme.</p>
  </div>
  
<?php
}

if ( $edition || $admin )  {
  // Textes affichés sur les éventuelles icônes du titre
  switch ( $matiere['protection'] )  {
    case 0: break;
    case 32:
      echo "  <div id=\"aide-affprotection\"><strong>Ces programmes de colles ne sont visibles que pour les professeurs associés à la matière.</strong> Ils ne sont pas accessibles aux autres utilisateurs.</div>\n\n";
      break;
    case 1:
      echo "  <div id=\"aide-affprotection\"><strong>Ces programmes de colles sont visibles par tous les utilisateurs connectés ayant saisi leur mot de passe, invisibles sans connexion</strong>.</div>\n\n";
      break;
    default:
      $p = $matiere['protection']-1;
      $comptes = array('invités','élèves','colleurs','comptes de type lycée','professeurs (même non associés à la matière)');
      $texte = preg_replace('/,([^,]+)$/',' et$1',implode(', les ',array_filter($comptes,function($a) use($p) { return !($p>>$a & 1); },ARRAY_FILTER_USE_KEY)));
      echo "  <div id=\"aide-affprotection\">En plus des professeurs associés à la matière, <strong>ces programmes de colles sont visibles par les $texte</strong>.</div>\n\n";
  }
}

fin($editionjs,$mathjax,$edition ? 'datetimepicker' : '');
?>
