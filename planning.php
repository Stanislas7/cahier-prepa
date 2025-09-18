<?php
// Sécurité
define('OK',1);
// Configuration
include('config.php');
// Fonctions
include('fonctions.php');

//////////////////
// Autorisation //
//////////////////

// Accès aux administrateurs, profs, et lycée. Redirection pour les autres.
// (mais édition possible uniquement pour les comptes administrateurs)
if ( $autorisation && ( $autorisation < 4 ) && !$_SESSION['admin'] )  {
  header("Location: https://$domaine$chemin");
  exit();
}
$mysqli = connectsql();
// Si non connecté, demande de connexion
// Si connexion light : on doit répéter son mot de passe pour aller plus loin
// login.php contient fin()
if ( !$autorisation || $_SESSION['light'] )  {
  $titre = 'Modification du planning';
  $actuel = 'planning';
  include('login.php');
}

//////////////
//// HTML ////
//////////////
debut($mysqli,'Modication du planning',$message,$autorisation,'planning',array('action'=>'planning'));
if ( $_SESSION['admin'] )
  echo "  <div id=\"icones\">\n    <a class=\"icon-ok\" title=\"Valider les modifications du planning\"></a>\n    <a class=\"icon-aide\" title=\"Aide pour les modifications du planning\"></a>\n  </div>";
else
  echo "  <div id=\"icones\">\n    <a class=\"icon-aide\" title=\"Aide pour les modifications du planning\"></a>\n  </div>\n  <div class=\"annonce\">Vous n'avez accès qu'en lecture à cette page. Seuls les utilisateurs disposant des droits d'administration peuvent modifier le planning annuel.</div>";
echo <<<FIN

  <article>
    <h3>Liste des semaines</h3>
    <form>
      <table id="planning">
        <thead>
          <tr>
            <th>Début de semaine</th>
            <th>Colles classiques</th>
            <th>Préparation à l'oral</th>
            <th>Vacances</th>
          </tr>
        </thead>
        <tbody>

FIN;

// Récupération des vacances
$resultat = $mysqli->query('SELECT id, nom FROM vacances WHERE id > 0 ORDER BY id');
$select_vacances = '<option value="0">Période scolaire</option>';
while ( $r = $resultat->fetch_row() )
  $select_vacances .= "<option value=\"${r[0]}\">${r[1]}</option>";
$resultat->free();
// Récupération et affichage des matières
$semaine = array('Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi');
$resultat = $mysqli->query('SELECT id, DATE_FORMAT(debut,\'%w\') AS jour, DATE_FORMAT(debut,\'%d/%m/%Y\') AS debut,
                                   colle, vacances, '. ( $_SESSION['admin'] ? 'COUNT(semaine)' : '1' ) .' AS readonly
                            FROM semaines LEFT JOIN (SELECT DISTINCT semaine FROM notescolles UNION SELECT DISTINCT semaine FROM progcolles ) AS np ON semaine = id
                            GROUP BY id ORDER BY id');
$nc = $no = 0;
while ( $r = $resultat->fetch_assoc() )  {
  $select = str_replace("\"${r['vacances']}\"","\"${r['vacances']}\" selected",$select_vacances);
  $r['jour'] = $semaine[$r['jour']];
  $readonly = ( $r['readonly'] > 0 ) ? ' disabled' : '';
  $colle = $oral = '';
  switch ( $r['colle'] )  {
    case 1: $colle = ' checked'; $nc += 1; break;
    case 2: $oral  = ' checked'; $no += 1; break;
  }
  echo <<<FIN
          <tr>
            <td>${r['jour']} ${r['debut']}</td>
            <td><input type="checkbox" name="colles[${r['id']}]"$readonly value="1"$colle></td>
            <td><input type="checkbox" name="oraux[${r['id']}]"$readonly value="1"$oral></td>
            <td><select name="vacances[${r['id']}]"$readonly>$select</select></td>
          </tr>

FIN;
}
$resultat->free();

// Fin du formulaire
?>
          <tr><td>Total cochées</td><td id="nc"><?php echo $nc; ?></td><td id="no"><?php echo $no; ?></td><td></td></tr>
        </tbody>
      </table>
    </form>
  </article>

<?php
if ( $_SESSION['admin'] )  {
?>

  <div id="aide-planning">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de modifier le planning annuel, c'est-à-dire pour chaque semaine de l'année, préciser s'il s'agit&nbsp;:</p>
    <ul>
      <li>d'une semaine de colle (case <em>Colles classiques</em> cochée), qui pourra recevoir des programmes de colles et des notes de colles.</li>
      <li>d'une semaine de préparation aux oraux (case <em>Préparation à l'oral</em> cochée), qui pourra recevoir des programmes de colles et des notes de colles.</li>
      <li>d'une semaine sans colle (case <em>Colle ou non</em> décochée), qui ne pourra recevoir ni programmes de colles, ni notes de colles.</li>
      <li>d'une semaine de vacances (colonne <em>Vacances</em>) qui ne pourra recevoir ni cahier de texte, ni programmes de colles, ni notes de colles.</li>
    </ul>
    <h4>Préparation à l'oral</h4>
    <p>Il y a une seule différence entre une semaine de <em>colles classiques</em> et une de <em>préparation à l'oral</em>&nbsp;: pour les <em>colles classiques</em>, un élève ne peut avoir qu'une seule note de colle par semaine et par matière au maximum. Ce n'est plus le cas en <em>préparation à l'oral</em>. La conséquence est que le tableau récapitulatif des notes de colles ne contient pas les notes mises en <em>préparation à l'oral</em>. Mais les élèves continuent de voir leurs notes sur leur page.</p>
    <h4>Semaines sans colles</h4>
    <p>Les vacances de deux semaines sont donc à marquer deux fois, une fois sur chaque semaine.</p>
    <p>Il est préférable de décocher les deux cases <em>Colle classiques</em> et <em>Préparation à l'oral</em> lorsque l'on sait qu'il n'y aura pas de colle, comme souvent en début ou en fin d'année&nbsp;: cela modifie l'affichage des programmes de colles (&laquo;&nbsp;Il n'y a pas de colle cette semaine&nbsp;&raquo; au lieu de &laquo;&nbsp;Le programme de colles de cette semaine n'est pas défini.&nbsp;&raquo;), et évite les erreurs d'écriture des programmes de colles ou de saisie des notes.</p>
    <p>Il n'est pas possible de rendre «&nbsp;semaine sans colle&nbsp;» une semaine où des programmes de colles ont déjà été fixés et/ou où des notes de colles ont déjà été données.</p>
    <p>La validation n'est pas faite à chaque modification, mais une seule fois globalement après un clic sur le bouton <span class="icon-ok"></span>.</p>
  </div>

<?php
}
else  {
?>  

  <div id="aide-planning">
    <h3>Aide et explications</h3>
    <p>Il est possible ici de visualiser le planning annuel. Les utilisateurs disposant des droits d'administration ont la possibilité de modifier ce planning.
    <p>Ce planning précise pour chaque semaine de l'année s'il s'agit&nbsp;:</p>
    <ul>
      <li>d'une semaine de colle (case <em>Colles classiques</em> cochée), qui pourra recevoir des programmes de colles et des notes de colles.</li>
      <li>d'une semaine de préparation aux oraux (case <em>Préparation à l'oral</em> cochée), qui pourra recevoir des programmes de colles et des notes de colles.</li>
      <li>d'une semaine sans colle (case <em>Colle ou non</em> décochée), qui ne pourra recevoir ni programmes de colles, ni notes de colles.</li>
      <li>d'une semaine de vacances (colonne <em>Vacances</em>) qui ne pourra recevoir ni cahier de texte, ni programmes de colles, ni notes de colles.</li>
    </ul>
    <h4>Préparation à l'oral</h4>
    <p>Il y a une seule différence entre une semaine de <em>colles classiques</em> et une de <em>préparation à l'oral</em>&nbsp;: pour les <em>colles classiques</em>, un élève ne peut avoir qu'une seule note de colle par semaine et par matière au maximum. Ce n'est plus le cas en <em>préparation à l'oral</em>. La conséquence est que le tableau récapitulatif des notes de colles ne contient pas les notes mises en <em>préparation à l'oral</em>. Mais les élèves continuent de voir leurs notes sur leur page.</p>
    <h4>Semaines sans colles</h4>
    <p>Les vacances de deux semaines sont donc à marquer deux fois, une fois sur chaque semaine.</p>
    <p>Il est préférable de décocher les deux cases <em>Colle classiques</em> et <em>Préparation à l'oral</em> lorsque l'on sait qu'il n'y aura pas de colle, comme souvent en début ou en fin d'année&nbsp;: cela modifie l'affichage des programmes de colles (&laquo;&nbsp;Il n'y a pas de colle cette semaine&nbsp;&raquo; au lieu de &laquo;&nbsp;Le programme de colles de cette semaine n'est pas défini.&nbsp;&raquo;), et évite les erreurs d'écriture des programmes de colles ou de saisie des notes.</p>
<?php
  $messageprof = ( $autorisation == 5 ) ? 'Vous pouvez modifier tout ce qui correspond à votre matière : <a href="matieres">fonctionnalités et droits d\'accès</a> (cahier de textes, programmes de colles, transferts de documents, notes de colles...), <a href="pages">pages d\'informations</a>.' : '';
  $resultat = $mysqli->query('SELECT GROUP_CONCAT(CONCAT(prenom," ",nom) ORDER BY nom SEPARATOR ", ") FROM utilisateurs WHERE autorisation > 10');
  $administrateurs = $resultat->fetch_row()[0];
  $resultat->free();
  echo <<< FIN
  
    <h4>Liste des administrateurs</h4>
    <p>Les utilisateurs disposant des droits d'administration de ce Cahier sont <strong>$administrateurs</strong>.</p>
    <p>N'hésitez pas à les contacter pour gérer les listes d'utilisateurs, le planning, les <a href="groupes">groupes</a> d'utilisateurs, ou ajouter une matière. $messageprof</p>
  </div>
FIN;
}

// Laisser true pour voir l'aide
fin(true);
?>
