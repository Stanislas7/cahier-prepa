<?php
// Définition de la base de données
// À utiliser en tant qu'utilisateur root
// Champs à remplacer : $base, $mdp, $serveur,
// $login, $nom, $prenom, $mail, $titre, $cle_matiere, $nom_matiere
//
// Ces définitions sont utilisables en php directement ou en shell linux par
// sed '1,/[ ]FIN/d ; N;$!P;$!D;$d' def_sql.php | sed "s/\\\$base/$BASE/g;s/\\\$serveur/$SERVEUR/g;..."

$requete = <<< FIN

DROP DATABASE IF EXISTS `$base`;
CREATE DATABASE `$base` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
DELETE FROM mysql.user WHERE User = '$base' OR User = '$base-adm';
CREATE USER '$base'@'localhost' IDENTIFIED WITH mysql_native_password BY '$mdp', '$base-adm'@'localhost' IDENTIFIED WITH mysql_native_password BY '$mdp';
DELETE FROM mysql.db WHERE Db = '$base';
INSERT INTO mysql.db (Host, Db, User, Select_priv, Insert_priv, Update_priv, Delete_priv, Alter_priv, Drop_priv) 
  VALUES ('$serveur', '$base', '$base', 'Y', 'N', 'N', 'N', 'N', 'N'),
         ('$serveur', '$base','$base-adm', 'Y', 'Y', 'Y', 'Y', 'Y', 'Y');
FLUSH PRIVILEGES;
USE `$base`;

-- pages, progcolles, cdt, docs, notescolles, transferts : 0 si vide, 1 si présent, 2 si désactivées
-- *_protection : valeur numérique de gestion de la protection. Si nul, autorisation à tous, sans
--    nécessité de connexion identifiée. Si entre 1 et 32, conversion de la valeur binaire PLCEI
--    (profs,lycée,colleurs,élèves,invités) après avoir retranché 1. Chaque 0 correspond
--    aux accès autorisés, chaque 1 correspond aux protections (accès interdit pour ce type de compte).
--    Exemple : 10->PLCEI=9=01001 -> accès autorisé pour P,C,E et interdit pour L et I.
--    L'interdiction pour les profs n'est pas valable pour les professeurs associés à la matière,
--    qui ont toujours accès aux ressources associées. 
--    L'autorisation pour les autres utilisateurs ne vaut que pour les matières associées.
--    Si p est la protection, l'accès global peut s'obtenir par 32-p. L'accès aux utilisateurs
--    d'autorisation a est calculé par (p-1)&2**a nul ou (32-p)&2**a non nul. 
--    Le code 32 (interdit pour tous) correspond à une fonction visible seulement pour les profs de la
--    matière. C'est indépendant de l'activation ou non de la fonctionnalité, qui est prioritaire, mais
--    on règle bien 32 si la fonction est désactivée.
--    docs_protection doit toujours être identique à la protection du répertoire de la matière.
--    
-- dureecolles : durée pour un élève, en minutes
-- heurescolles : 0 si décompte à l'élève, 1 si arrondi à la première heure pleine pour chaque déclaration
CREATE TABLE `matieres` (
  `id` tinyint(2) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `ordre` tinyint(2) unsigned NOT NULL,
  `cle` varchar(50) NOT NULL,
  `nom` varchar(50) NOT NULL,
  `progcolles` tinyint(1) unsigned NOT NULL,
  `cdt` tinyint(1) unsigned NOT NULL,
  `docs` tinyint(1) unsigned NOT NULL,
  `notescolles` tinyint(1) unsigned NOT NULL,
  `transferts` tinyint(1) unsigned NOT NULL,
  `progcolles_protection` tinyint(1) unsigned NOT NULL,
  `cdt_protection` tinyint(1) unsigned NOT NULL,
  `docs_protection` tinyint(1) unsigned NOT NULL,
  `transferts_protection` tinyint(1) unsigned NOT NULL,
  `dureecolles` tinyint(1) unsigned NOT NULL,
  `heurescolles` tinyint(1) unsigned NOT NULL,
  KEY `progcolles` (`progcolles`),
  KEY `cdt` (`cdt`),
  KEY `docs` (`docs`),
  KEY `notescolles` (`notescolles`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- autorisation : type d'utilisateur (1:invité, 2:élève, 3:colleur, 4:lycée, 5:professeur)
-- autorisation = type + 10 pour les comptes administrateurs
-- mdp : stockage du mot de passe sur 40 caractères
--       * si commence par un ? : invitation non répondue (mot de passe non défini)
--       * si commence par un * : compte demandé en attente de validation
--       * si commence par un ! : compte suspendu
--       remarque : ASCII -> !=33, *=52, 0=60, ?=63 
-- matieres : liste des matières associées, commençant par zéro pour les parties 
--    non spécifiques à des matières. Pour les profs, un numéro de matière précédé du
--    caractère 'c' correspond à une matière où le prof n'est que colleur.
-- mailexp : nom d'expédition des courriels
-- mailcopie : si par défaut envoi personnel d'une copie de ses courriels
-- permconn : token d'identification légère, par cookie
-- lastconn : horodatage de la connexion actuelle
-- menumatieres : liste des matières à afficher dans le menu, a priori égale à matieres,
--    mais modifiable pour les profs (on peut ajouter des matières non associées).
--    Pour les comptes lycées et les profs qui le souhaitent, le C dans la liste 
--    correspond à l'affichage de l'élément "Relève des colles"
-- menuelements : liste complexe permettant de fabriquer le menu, automatiquement mise
--    à jour si besoin (après déplacement de matière, suppression d'élément...) 
CREATE TABLE `utilisateurs` (
  `id` smallint(3) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `login` varchar(50) NOT NULL UNIQUE,
  `nom` varchar(50) NOT NULL,
  `prenom` varchar(50) NOT NULL,
  `mail` varchar(60) NOT NULL,
  `autorisation` tinyint(1) UNSIGNED NOT NULL,
  `mdp` char(41) NOT NULL,
  `matieres` varchar(50) NOT NULL,
  `timeout` smallint(4) UNSIGNED NOT NULL,
  `mailexp` varchar(50) NOT NULL,
  `mailcopie` tinyint(1) UNSIGNED NOT NULL,
  `permconn` varchar(10) NOT NULL,
  `lastconn` datetime NOT NULL,
  `menumatieres` varchar(50) NOT NULL,
  `menuelements` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- protection : cf matieres. Le code 32 est ici utilisé pour les pages non
--    affichées dans le menu, non visible sauf pour
--    les professeurs associés à la matière
-- edition : valeur numérique de gestion de l'édition. Si nul, édition autorisée
--    uniquement aux profs associés à la matière (cas par défaut) et comptes
--    administrateurs. Valeur 32 non utilisée.
--    Si entre 1 et 32, conversion de la valeur binaire PLCEI (profs,lycée,colleurs,élèves,
--    invités) après y avoir retranché 1. Chaque 1 correspond à une autorisation d'édition.
--    Pour les profs, le 1 étend l'autorisation d'édition aux profs non associés à la matière.
--    Pour les autres types, l'autorisation n'est valable que pour les utilisateurs associés
--    à la matière.
--    Seul un utilisateur ayant accès en lecture peut avoir accès en écriture.
--    Il est a priori peu recommandé de donner un accès en édition aux élèves.
CREATE TABLE `pages` (
  `id` tinyint(2) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `ordre` tinyint(2) unsigned NOT NULL,
  `cle` varchar(50) NOT NULL,
  `matiere` tinyint(2) unsigned NOT NULL,
  `nom` varchar(50) NOT NULL,
  `titre` text NOT NULL,
  `bandeau` text NOT NULL,
  `protection` tinyint(1) unsigned NOT NULL,
  `edition` tinyint(1) unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `infos` (
  `id` smallint(4) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `ordre` tinyint(2) unsigned NOT NULL,
  `page` tinyint(2) unsigned NOT NULL,
  `cache` tinyint(1) unsigned NOT NULL,
  `titre` text NOT NULL,
  `texte` text NOT NULL,
  `protection` tinyint(1) unsigned NOT NULL,
  `edition` tinyint(1) unsigned NOT NULL,
  `dispo` datetime NOT NULL,
  KEY `ordre` (`ordre`,`page`),
  KEY `cache` (`cache`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `semaines` (
  `id` tinyint(2) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `debut` date NOT NULL,
  `colle` tinyint(1) unsigned NOT NULL,
  `vacances` tinyint(1) unsigned NOT NULL,
  KEY `debut` (`debut`),
  KEY `colle` (`colle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `vacances` (
  `id` tinyint(2) unsigned NOT NULL PRIMARY KEY,
  `nom` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `progcolles` (
  `id` tinyint(3) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `semaine` tinyint(2) unsigned NOT NULL,
  `matiere` tinyint(2) unsigned NOT NULL,
  `texte` text NOT NULL,
  `cache` tinyint(1) NOT NULL,
  `dispo` datetime NOT NULL,
  KEY `semaine` (`semaine`),
  KEY `matiere` (`matiere`),
  KEY `cache` (`cache`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `cdt` (
  `id` smallint(3) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `matiere` tinyint(2) unsigned NOT NULL,
  `semaine` tinyint(2) unsigned NOT NULL,
  `jour` date NOT NULL,
  `h_debut` time NOT NULL,
  `h_fin` time NOT NULL,
  `pour` date NOT NULL,
  `type` tinyint(2) unsigned NOT NULL,
  `texte` text NOT NULL,
  `demigroupe` tinyint(1) unsigned NOT NULL,
  `cache` tinyint(1) unsigned NOT NULL,
  `dispo` datetime NOT NULL,
  KEY `matiere` (`matiere`),
  KEY `semaine` (`semaine`),
  KEY `type` (`type`),
  KEY `cache` (`cache`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `cdt-types` (
  `id` tinyint(2) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `matiere` tinyint(2) unsigned NOT NULL,
  `ordre` tinyint(2) unsigned NOT NULL,
  `titre` varchar(50) NOT NULL,
  `cle` varchar(20) NOT NULL,
  `deb_fin_pour` tinyint(1) unsigned NOT NULL,
  `nb` tinyint(2) unsigned NOT NULL,
  KEY `matiere` (`matiere`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `cdt-seances` (
  `id` tinyint(2) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `matiere` tinyint(2) unsigned NOT NULL,
  `ordre` tinyint(2) unsigned NOT NULL,
  `nom` varchar(40) NOT NULL,
  `jour` tinyint(1) unsigned NOT NULL,
  `h_debut` time NOT NULL,
  `h_fin` time NOT NULL,
  `type` tinyint(3) unsigned NOT NULL,
  `demigroupe` tinyint(1) unsigned NOT NULL,
  `protection` tinyint(1) unsigned NOT NULL,
  `template` text NOT NULL,
  KEY `matiere` (`matiere`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `reps` (
  `id` smallint(3) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `parent` smallint(3) unsigned NOT NULL,
  `parents` varchar(50) NOT NULL,
  `matiere` tinyint(2) unsigned NOT NULL,
  `nom` varchar(100) NOT NULL,
  `protection` tinyint(1) unsigned NOT NULL,
  `edition` tinyint(1) unsigned NOT NULL,
  `menu` tinyint(1) unsigned NOT NULL,
  `zip` tinyint(1) unsigned NOT NULL,
  KEY `parent` (`parent`),
  KEY `matiere` (`matiere`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `docs` (
  `id` smallint(3) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `parent` smallint(3) unsigned NOT NULL,
  `parents` varchar(50) NOT NULL,
  `matiere` tinyint(2) unsigned NOT NULL,
  `nom` varchar(100) NOT NULL,
  `nom_nat` varchar(100) NOT NULL,
  `upload` date NOT NULL,
  `taille` varchar(12) NOT NULL,
  `lien` char(15) NOT NULL,
  `ext` varchar(10) NOT NULL,
  `protection` tinyint(1) unsigned NOT NULL,
  `dispo` datetime NOT NULL,
  KEY `parent` (`parent`),
  KEY `matiere` (`matiere`),
  KEY `nom_nat` (`nom_nat`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `recents` (
  `id` smallint(5) UNSIGNED NOT NULL,
  `type` tinyint(1) UNSIGNED NOT NULL,
  `publi` datetime NOT NULL,
  `maj` datetime NOT NULL,
  `titre` varchar(200) NOT NULL,
  `lien` varchar(30) NOT NULL,
  `texte` text NOT NULL,
  `protection` tinyint(1) UNSIGNED NOT NULL,
  `matiere` tinyint(2) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`,`type`),
  KEY `publi` (`publi`),
  KEY `maj` (`maj`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `notescolles` (
  `id` smallint(5) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `semaine` tinyint(2) unsigned NOT NULL,
  `heure` smallint(3) unsigned NOT NULL,
  `eleve` smallint(3) unsigned NOT NULL,
  `colleur` smallint(3) unsigned NOT NULL,
  `matiere` tinyint(2) unsigned NOT NULL,
  `note` varchar(4) NOT NULL,
  `commentaire` text NOT NULL,
  KEY `semaine` (`semaine`),
  KEY `heure` (`heure`),
  KEY `eleve` (`eleve`),
  KEY `colleur` (`colleur`),
  KEY `matiere` (`matiere`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `heurescolles` (
  `id` smallint(5) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `colleur` smallint(3) unsigned NOT NULL,
  `matiere` tinyint(2) unsigned NOT NULL,
  `jour` date NOT NULL,
  `rattrapage` date NOT NULL,
  `duree` smallint(3) unsigned NOT NULL,
  `description` varchar(200) NOT NULL,
  `releve` date NOT NULL,
  `original` smallint(3) unsigned NOT NULL,
  KEY `colleur` (`colleur`),
  KEY `matiere` (`matiere`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `groupes` (
  `id` tinyint(2) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `nom` varchar(50) NOT NULL,
  `nom_nat` varchar(50) NOT NULL,
  `mails` tinyint(1) UNSIGNED NOT NULL,
  `notes` tinyint(1) UNSIGNED NOT NULL,
  `utilisateurs` varchar(250) NOT NULL
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE `agenda` (
  `id` smallint(3) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `matiere` tinyint(2) unsigned NOT NULL,
  `type` tinyint(2) unsigned NOT NULL,
  `debut` datetime NOT NULL,
  `fin` datetime NOT NULL,
  `texte` text NOT NULL,
  `protection` tinyint(1) unsigned NOT NULL,
  `edition` tinyint(1) unsigned NOT NULL,
  `dispo` datetime NOT NULL,
  `index_aff` tinyint(2) unsigned NOT NULL,
  KEY `matiere` (`matiere`),
  KEY `type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `agenda-types` (
  `id` tinyint(2) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `nom` varchar(50) NOT NULL,
  `matiere` tinyint(2) unsigned NOT NULL,
  `ordre` tinyint(2) unsigned NOT NULL,
  `cle` varchar(20) NOT NULL,
  `couleur` varchar(6) NOT NULL,
  `index_nbmax` tinyint(2) unsigned NOT NULL,
  `index_datemax` tinyint(2) unsigned NOT NULL,
  `template` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `prefs` (
  `nom` varchar(50) NOT NULL,
  `val` smallint(3) unsigned NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- type : sens * 1 + autorisation_colleurs * 2 + autorisation_lycée * 4
--                 + autorisation_profs * 8
--        sens : 0 si depuis les élèves, 1 si vers les élèves
--        autorisations : 0 si non, 1 si oui
--        profs = profs non associés
--        accès systématique pour les élèves et les profs associés à la matière
--        sans matière, on met 1 pour l'autorisation des profs 
-- titre est le titre affiché, prefixe est écrit au début des noms de fichiers
-- indication est un paragraphe éventuel d'indications
CREATE TABLE `transferts` (
  `id` smallint(5) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `type` tinyint(2) unsigned NOT NULL,
  `matiere` tinyint(2) unsigned NOT NULL,
  `deadline` datetime NOT NULL,
  `titre` text NOT NULL,
  `prefixe` varchar(15) NOT NULL,
  `lien` char(15) NOT NULL,
  `indications` text NOT NULL,
  `dispo` datetime NOT NULL,
  KEY `matiere` (`matiere`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

-- numero sert à numéroter les envois
CREATE TABLE `transdocs` (
  `id` smallint(5) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `transfert` smallint(5) unsigned NOT NULL,
  `eleve` smallint(3) unsigned NOT NULL,
  `utilisateur` smallint(3) unsigned NOT NULL,
  `numero` tinyint(2) unsigned NOT NULL,
  `upload` datetime NOT NULL,
  `taille` varchar(12) NOT NULL,
  `ext` varchar(10) NOT NULL,
  KEY `transfert` (`transfert`),
  KEY `eleve` (`eleve`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

-- Options
-- creation_compte : 0 ou 1 (demande autorisée ou non)
-- agenda_protection : gestion de l'accès, cf matieres
-- agenda_edition : gestion de l'édition, cf matieres
-- agenda_nbmax : nombre maximal d'éléments d'agenda affichés sur la page d'accueil
-- agenda_datemax : nombre maximal de jours d'affichage de l'agenda sur la page d'accueil
-- agenda_vue : type de vue par défaut de l'agenda (1 : par mois, 2 : par semaine)
-- autorisation_mails : gestion de l'autorisation d'envoi de mail, voir utilisateurs_mails.php
-- transfert_general : transfert de docs sans matière associée, 0 si vide, 2 si désactivé
-- icones_auto0 : icones du menu pour les utilisateurs non connectés, sommme de
--   1 pour les docs, 2 pour les programmes de colles, 4 pour l'agenda
INSERT INTO prefs (nom,val)
  VALUES ('creation_compte',1),
         ('agenda_datemax',7),
         ('agenda_edition',0),
         ('agenda_nbmax',10),
         ('agenda_protection',0),
         ('agenda_vue',1),
         ('autorisation_mails',61440),
         ('transferts_general',2),
         ('transferts_general_protection',32),
         ('icones_auto0',4);

INSERT INTO utilisateurs (id,login,prenom,nom,mail,mdp,autorisation,matieres,timeout,mailexp,mailcopie,menumatieres)
  VALUES (1, '$login', '$prenom', '$nom', '$mail', '?', 15, '0,1', 900, '$prenom $nom', 1, '0,1');

INSERT INTO matieres (id,ordre,cle,nom,transferts_protection,dureecolles,heurescolles)
  VALUES (1, 1, '$cle_matiere', '$nom_matiere', 32, 20, 1);

INSERT INTO reps (id,parents,matiere,nom,menu)
  VALUES (1, '0', 0, 'Général',1),
         (2, '0', 1, '$nom_matiere',0);

INSERT INTO pages (ordre,cle,nom,titre,bandeau)
  VALUES (1, 'accueil', 'Accueil', '$titre', 'Dernières informations importantes');

INSERT INTO `cdt-types` (matiere,ordre,cle,titre,deb_fin_pour)
  VALUES (1, 1, 'cours', 'Cours', 1),
         (1, 2, 'TD', 'Séance de travaux dirigés', 1),
         (1, 3, 'TP', 'Séance de travaux pratiques', 1),
         (1, 4, 'DS', 'Devoir surveillé', 1),
         (1, 5, 'interros', 'Interrogation de cours', 0),
         (1, 6, 'distributions', 'Distribution de document', 0),
         (1, 7, 'DM', 'Devoir maison', 2);

INSERT INTO `agenda-types` (id,ordre,nom,cle,couleur,index_nbmax,index_datemax)
  VALUES (1, 1, 'Cours', 'cours', 'CC6633', 10, 7),
         (4, 2, 'Devoir surveillé', 'DS', '6633CC', 10, 7),
         (5, 3, 'Devoir maison', 'DM', '99CC33', 10, 7),
         (6, 4, 'Divers','div', 'CCCC33', 10, 7),
         (2, 5, 'Jour férié', 'fer', 'CC3333', 10, 7),
         (3, 6, 'Vacances', 'vac', '66CC33', 10, 7);

-- 
-- Valeurs du calendrier, exemple pour 2025-2026
-- 
-- INSERT INTO semaines (id,debut) VALUES 
--  (1,'2025-09-01'), (2,'2025-09-08'), (3,'2025-09-15'), (4,'2025-09-22'), (5,'2025-09-29'),
--  (6,'2025-10-06'), (7,'2025-10-13'), (8,'2025-10-20'), (9,'2025-10-27'),(10,'2025-11-03'),
--  (11,'2025-11-10'),(12,'2025-11-17'),(13,'2025-11-24'),(14,'2025-12-01'),(15,'2025-12-08'),
--  (16,'2025-12-15'),(17,'2025-12-22'),(18,'2025-12-29'),(19,'2026-01-05'),(20,'2026-01-12'),
--  (21,'2026-01-19'),(22,'2026-01-26'),(23,'2026-02-02'),(24,'2026-02-09'),(25,'2026-02-16'),
--  (26,'2026-02-23'),(27,'2026-03-02'),(28,'2026-03-09'),(29,'2026-03-16'),(30,'2026-03-23'),
--  (31,'2026-03-30'),(32,'2026-04-06'),(33,'2026-04-13'),(34,'2026-04-20'),(35,'2026-04-27'),
--  (36,'2026-05-04'),(37,'2026-05-11'),(38,'2026-05-18'),(39,'2026-05-25'),(40,'2026-06-01'),
--  (41,'2026-06-08'),(42,'2026-06-15'),(43,'2026-06-22'),(44,'2026-06-29'),(45,'2026-07-06');

-- INSERT INTO vacances (id, nom) VALUES
--   (1, 'Vacances de la Toussaint'),
--   (2, 'Vacances de Noël'),
--   (3, "Vacances d'hiver"),
--   (4, 'Vacances de printemps');

-- Planning de la zone C
-- UPDATE semaines SET vacances = 1 WHERE id = 8 OR id = 9;
-- UPDATE semaines SET vacances = 2 WHERE id = 17 OR id = 18;
-- UPDATE semaines SET vacances = 3 WHERE id = 26 OR id = 27;
-- UPDATE semaines SET vacances = 4 WHERE id = 34 OR id = 35;
-- UPDATE semaines SET colle = 1 WHERE vacances = 0;  

-- INSERT INTO agenda (id, matiere, type, debut, fin, texte) VALUES
--   ( 1, 0, 1, '2025-09-01 00:00:00', '2025-09-01 00:00:00', '<div class=\"annonce\">C\'est la rentrée ! Bon courage pour cette nouvelle année&nbsp;!</div>'),
--   ( 2, 0, 2, '2025-11-01 00:00:00', '2025-11-01 00:00:00', '<p>Toussaint</p>'),
--   ( 3, 0, 2, '2025-11-11 00:00:00', '2025-11-11 00:00:00', '<p>Armistice 1918</p>'),
--   ( 4, 0, 2, '2025-12-25 00:00:00', '2025-12-25 00:00:00', '<p>Noël</p>'),
--   ( 5, 0, 2, '2026-01-01 00:00:00', '2026-01-01 00:00:00', '<p>Jour de l\'an</p>'),
--   ( 6, 0, 2, '2025-04-06 00:00:00', '2025-04-06 00:00:00', '<p>Lundi de Pâques</p>'),
--   ( 7, 0, 2, '2026-05-01 00:00:00', '2026-05-01 00:00:00', '<p>Fête du travail</p>'),
--   ( 8, 0, 2, '2026-05-08 00:00:00', '2026-05-08 00:00:00', '<p>Armistice 1945</p>'),
--   ( 9, 0, 2, '2025-05-14 00:00:00', '2025-05-17 00:00:00', '<p>Pont de l\'Ascension</p>'),
--   (10, 0, 2, '2025-05-25 00:00:00', '2025-05-25 00:00:00', '<p>Lundi de Pentecôte</p>'),
--   (11, 0, 2, '2026-07-14 00:00:00', '2026-07-14 00:00:00', '<p>Fête Nationale</p>'),
--   (12, 0, 3, '2025-07-06 00:00:00', '2025-08-31 00:00:00', '<p>Vacances d\'été</p>'),
--   (13, 0, 3, '2025-10-19 00:00:00', '2025-11-02 00:00:00', '<p>Vacances de la Toussaint</p>'),
--   (14, 0, 3, '2025-12-21 00:00:00', '2026-01-04 00:00:00', '<p>Vacances de Noël</p>'),
--   (15, 0, 3, '2026-02-22 00:00:00', '2026-03-08 00:00:00', '<p>Vacances d\'hiver</p>'),
--   (16, 0, 3, '2026-04-19 00:00:00', '2026-05-04 00:00:00', '<p>Vacances de printemps</p>'),
--   (17, 0, 3, '2026-07-05 00:00:00', '2026-08-30 00:00:00', '<p>Vacances d\'été</p>');

FIN;
?>
