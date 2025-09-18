<?php
// Sécurité : ne doit pas être exécuté s'il n'est pas lancé par un script autorisé
if ( !defined('OK') )  exit();

// Script d'affichage des transferts de documents personnels, spécifique aux élèves
// Script lancé par transferts.php
// Autorisation obligatoirement égale à 2, sauf si mode lecture 
// Fonction genere_recherche() définie dans transferts_fonctions.php

// MathJax désactivé par défaut
$mathjax = false;

// Fonction d'affichage d'un transfert
// $t doit contenir id,titre,envoi,date,heure,encours,indications, lien et éventuellement matiere
// Si pas de deadline, date 
function affiche_transfert($eid,$t,$mysqli)  {
  global $mathjax;
  // Formatage des données du transfert
  $id = $t['id'];
  $titre = ( isset($t['matiere']) ? "${t['matiere']}&nbsp;: ${t['titre']}" : $t['titre'] );
  if ( $t['envoi'] )  {
    $date = ( $t['date'] ) ? '<strong>'.format_date($t['date'])." à ${t['heure']}</strong>" : 'sans date limite';
    if ( $t['encours'] )  {
      $encours = ( $t['date'] ) ? "\n    <p><span class=\"ok\">&nbsp;Envoi possible&nbsp;</span> jusqu'au <strong>".format_date($t['date'])." à ${t['heure']}</strong></p>"
                                : "\n    <p><span class=\"ok\">&nbsp;Envoi possible&nbsp;</span> (sans date limite)</p>";
      $envoi = "\n    <form>\n      <input type=\"hidden\" name=\"action\" value=\"ajout-transdocs\">\n      <p class=\"ligne\"><label for=\"fichier$id\">Envoyer un document&nbsp;:</label> <input type=\"file\" name=\"fichier[]\" id=\"fichier$id\"> <button class=\"icon-ok\" title=\"Envoyer\"></button></p>\n    </form>";
    }
    else  {
      $encours =  "\n    <p><span class=\"nok\">&nbsp;Envoi terminé&nbsp;</span> (date limite d'envoi <strong>".format_date($t['date'])." à ${t['heure']}</strong>)</p>";
      $envoi = '';
    }
    $tableexp = 'LEFT JOIN utilisateurs AS u ON utilisateur = u.id';
    $requeteexp = ', nom AS exp';
  }
  else
    $encours = $envoi = $tableexp = $requeteexp = '';
  $indications = ( $t['indications'] ? "\n    <p><strong>Consignes/Indications&nbsp;:</strong></p>\n    <div class=\"indications\">${t['indications']}</div>" : '' );
  $mathjax = $mathjax ?: boolval(strpos($t['indications'],'$')+strpos($t['indications'],'\\'));
  // Récupération des documents déjà envoyés
  $resultat = $mysqli->query("SELECT d.id, numero, DATE_FORMAT(upload,'%e/%m/%y, %kh%i') AS upload, taille, ext, nom AS exp
                              FROM transdocs AS d LEFT JOIN utilisateurs AS u ON utilisateur = u.id
                              WHERE eleve = $eid AND transfert = $id ORDER BY numero");
  if ( $n = $resultat->num_rows )  {
    $docs = '';
    while ( $r = $resultat->fetch_assoc() )  {
      if ( $t['envoi'] )  {
        $nom = 'Document envoyé'.( ( $n == 1 ) ? '' : " n°${r['numero']}" );
        $supprime = '<a class="icon-supprime" title="Supprimer ce document"></a>';
      }
      else  {
        $nom = 'Document reçu'.( $r['exp'] ? " de ${r['exp']}" : '' ).( ( $n == 1 ) ? '' : " n°${r['numero']}" );
        $supprime = '';
      }
      $icone = transforme_extension($r['ext']);
      $type = substr(transforme_extension($r['ext'],1), 0,5);
      $verif = sha1("d${r['id']}-${_SESSION['id']}-${t['lien']}-${GLOBALS['mdp']}");
      $lienvoir = '';
      if ( $type == 'video' )
        $lienvoir = "<a class=\"icon-play\" href=\"transferts?dl=${r['id']}&amp;t=$id&amp;verif=$verif&amp;voir\" title=\"Voir directement ici la vidéo\"></a>&nbsp;";
      elseif ( $type == 'audio' )
        $lienvoir = "<a class=\"icon-play\" href=\"transferts?dl=${r['id']}&amp;t=$id&amp;verif=$verif&amp;voir\" title=\"Écouter directement ici l'audio\"></a>&nbsp;";
      elseif ( $r['ext'] == 'py' )
        $lienvoir = "<a class=\"icon-play\" href=\"transferts?dl=${r['id']}&amp;t=$id&amp;verif=$verif&amp;voir\" title=\"Tester directement ici le fichier Python (dans Basthon)\"></a>&nbsp;";
      elseif ( $r['ext'] == 'sql' )
        $lienvoir = "<a class=\"icon-play\" href=\"transferts?dl=${r['id']}&amp;t=$id&amp;verif=$verif&amp;voir\" title=\"Tester directement ici le fichier SQL (dans Basthon)\"></a>&nbsp;";
      $docs .= "\n    <p class=\"transdoc\" data-id=\"${r['id']}\" data-verif=\"$verif\"><a><span class=\"$icone\"></span>&nbsp;<span class=\"nom\">$nom</span></a> <span class=\"date\">(${r['upload']} - ${r['taille']})</span> $lienvoir<a class=\"icon-download\" title=\"Télécharger ce document\"></a>&nbsp;$supprime</p>";
    }
    $resultat->free();
  }
  else 
    $docs = ( $t['envoi'] ? "\n    <p><strong>Vous n'avez pas ".( $t['encours'] ? 'encore' : '' )." envoyé de document pour ce transfert.</strong></p>" : "\n    <p><strong>Vous n'avez pas reçu de document pour ce transfert.</strong></p>");
  // Affichage
  echo <<< FIN
          
  <article class="transfert" data-id="$id">
    <h3>$titre</h3>$encours$indications$envoi$docs
  </article>

FIN;
}

// Mode lecture
// On vient de transferts.php, on a déjà affiché le bandeau et le menu
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
    // Affichage : barre de sélection de l'élève
    $select = str_replace("\"$eid\"","\"$eid\" selected",$select);
    $icones = "\n  <div id=\"icones\">\n    <a class=\"icon-lecture mev\" title=\"Modifier le mode de lecture\"></a>\n  </div>\n\n  <p id=\"selecteleve\" class=\"topbarre\"><span>Voir ce que voit l'élève</span>\n    <select>\n$select\n    </select>\n  </p>\n";  
  }
  else  {
    debut($mysqli,'Notes de colles',$message,$autorisation,'notescolles',$donnees);
    echo "$icones\n  <article><h2>Il n'y a aucun élève disponible.</h2></article>\n\n";
    fin(true);
  }
}
else 
  $eid = $_SESSION['id'];

/////////////////////////////////////////////////
// Pas de matière demandée : affichage spécial //
/////////////////////////////////////////////////
// Affichage des transferts en cours attendant participation
// Affichage des documents récents à récupérer
// Affichage de la liste des matières si plusieurs possibles
// Si une seule matière : affichage direct de la matière.
if ( empty($_GET) )  {
  $resultat = $mysqli->query("SELECT m.id, cle, nom, transferts, COUNT(t.id) AS n
                              FROM $tablematieres LEFT JOIN transferts AS t ON m.id = t.matiere
                              WHERE FIND_IN_SET(m.id,'${_SESSION['matieres']}') AND transferts = 1 AND dispo < NOW()
                              GROUP BY m.id ORDER BY ordre");
  if ( $n = $resultat->num_rows )  {
    // Si une seule matière trouvée, réglage automatique sur cette matière
    if ( $n == 1 )  {
      $matiere = $resultat->fetch_assoc();
      $resultat->free();
    }
    // Si plusieurs matières trouvées, affichage spécial
    else  {
      // Toutes matières
      debut($mysqli,'Transferts de documents personnels',$message,$autorisation,'transferts',$donnees ?? false);
      echo "$icones\n  <article>\n  <h2>Mes matières</h2>";
      while ( $r = $resultat->fetch_assoc() )
        if ( $r['n'] )
          echo "\n    <h3 class=\"detailmatiere\"><a href=\"transferts?${r['cle']}\">${r['nom']}</a><span>(${r['n']} transfert".( $r['n'] > 1 ? 's' : '' ).')</span></h3>';
      echo "\n  </article>\n";
      $resultat->free();
      
      // Affichage des transferts attendant participation
      $resultat = $mysqli->query("SELECT cle, nom AS matiere, t.id, titre, 1 AS envoi, IF(YEAR(deadline)<2100,DATE_FORMAT(deadline,'%w%Y%m%e'),0) AS date,
                                  DATE_FORMAT(deadline,'%kh%i') AS heure, (deadline > NOW()) AS encours, indications, lien
                                  FROM transferts AS t LEFT JOIN $tablematieres ON m.id = t.matiere
                                  WHERE type & 1 = 0 AND dispo < NOW() AND deadline > ADDTIME(NOW(),'2:00') AND FIND_IN_SET(m.id,'${_SESSION['matieres']}')
                                  ORDER BY ordre, deadline DESC");
      if ( $resultat->num_rows )  {
        echo "\n  <h2 class=\"edition\">Transferts en cours</h2>";
        while ( $r = $resultat->fetch_assoc() )
          affiche_transfert($eid,$r,$mysqli);
        $resultat->free();
      }
      
      // Affichage des documents récents à récupérer
      $resultat = $mysqli->query("SELECT cle, nom AS matiere, t.id, titre, 0 AS envoi, IF(YEAR(deadline)<2100,DATE_FORMAT(deadline,'%w%Y%m%e'),0) AS date,
                                  DATE_FORMAT(deadline,'%kh%i') AS heure, 0 AS encours, indications, lien
                                  FROM transdocs AS d LEFT JOIN transferts AS t ON d.transfert = t.id LEFT JOIN $tablematieres ON m.id = t.matiere
                                  WHERE type & 1 AND eleve = $eid AND dispo < NOW() AND DATEDIFF(NOW(),upload) < 50
                                  GROUP BY t.id ORDER BY MAX(upload) DESC");
      if ( $resultat->num_rows )  {
        echo "\n  <h2 class=\"edition\">Documents récents</h2>";
        $n = 0;
        while ( ( $r = $resultat->fetch_assoc() ) && ( $n < 15 ) )  {
          affiche_transfert($eid,$r,$mysqli);
          $n++;
        }
        $resultat->free();
      }
      $mysqli->close();
      fin($editionjs ?? false, $mathjax);
    }
  }
  // Pas de matière concernée !
  else  {
    debut($mysqli,'Transferts de documents personnels','Cette page ne contient aucune information.',2,'transferts');
    $mysqli->close();
    fin();
  }
}

////////////////////////////////////////
// Validation de la requête : matière //
////////////////////////////////////////

// Recherche de la matière concernée, variable $matiere
// Si le compte n'est associé qu'à une matière, on la choisit automatiquement.
// Sinon, on cherche $_REQUEST['cle'] dans les matières disponibles.
if ( !isset($matiere) )  {
  $resultat = $mysqli->query("SELECT id, cle, nom FROM $tablematieres WHERE FIND_IN_SET(id,'${_SESSION['matieres']}') AND transferts = 1");
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
      debut($mysqli,'Transferts de documents personnels','Mauvais paramètre d\'accès à cette page.',2,' ');
      $mysqli->close();
      fin();
    }
  }
  // Si aucune matière avec des transferts n'est enregistrée
  else  {
    debut($mysqli,'Transferts de documents personnels','Cette page ne contient aucune information.',2,' ');
    $mysqli->close();
    fin();
  }
}
$mid = $matiere['id'];
$cle = $matiere['cle'];

////////////
/// HTML ///
////////////
debut($mysqli,"Transferts de documents personnels - ${matiere['nom']}",$message,$autorisation,"transferts?${matiere['cle']}", $donnees ?? false);

// Barre de recherche
echo $icones;
$requete = genere_recherche($mysqli,2,$cle);

// Récupération de l'ensemble des transferts
$resultat = $mysqli->query("SELECT id, titre, type & 1 = 0 AS envoi, IF(YEAR(deadline)<2100,DATE_FORMAT(deadline,'%w%Y%m%e'),0) AS date,
                            DATE_FORMAT(deadline,'%kh%i') AS heure, (deadline > NOW()) AS encours, indications, lien
                            FROM transferts WHERE matiere = $mid AND dispo < NOW() ". ( $requete ?: 'ORDER BY deadline DESC, dispo DESC' ) );
if ( $resultat->num_rows )  {
  while ( $r = $resultat->fetch_assoc() )
    affiche_transfert($eid,$r,$mysqli);
  $resultat->free();
  // Tutoriel d'utilisation
  if ( $interfaceglobale )
    echo "\n  <article><h3>Besoin d'aide&nbsp;?</h3><p>N'hésitez pas à visualiser le <a href=\"/tutoriel_scan\" target=\"blank\">tutoriel pour bien numériser les feuilles A4, les copies...</a>.</p></article>\n";
}
// Pas de transfert 
elseif ( $requete )  
  echo "\n  <article>\n    <h3>Aucun transfert de documents ".( $mid ? "en ${matiere['nom']}" : 'sans matière associée')." ne correspond à cette recherche.</h3>\n    <p class=\"center\"><a href=\"?$cle\">Annuler la recherche</a>.</p>\n  </article>\n";
else
  echo "\n  <article>\n    <h2>Aucun transfert de documents n'a encore été organisé ".( $mid ? "en ${matiere['nom']}" : 'sans matière associée')." cette année.</h2>\n  </article>\n";
$mysqli->close();

fin($editionjs ?? false,$mathjax);
?>
