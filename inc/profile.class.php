<?php
/*
 * @version $Id: profile.class.php 154 2013-07-11 09:26:04Z yllen $
 LICENSE

 This file is part of the uninstall plugin.

 Uninstall plugin is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 Uninstall plugin is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with uninstall. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 @package   uninstall
 @author    the uninstall plugin team
 @copyright Copyright (c) 2010-2013 Uninstall plugin team
 @license   GPLv2+
            http://www.gnu.org/licenses/gpl.txt
 @link      https://forge.indepnet.net/projects/uninstall
 @link      http://www.glpi-project.org/
 @since     2009
 ---------------------------------------------------------------------- */

class PluginUninstallProfile extends Profile {

   static $rightname = "profile";


   function showForm($ID, $options=array()) {
      global $DB;
      
      $profile = new Profile();
      
      if ($ID) {
         $this->getFromDB($ID);
         $profile->getFromDB($ID);
      } else {
         $this->getEmpty();
      }
      
      if ($canedit = Session::haveRightsOr(self::$rightname, array(CREATE, UPDATE, PURGE))) {
         $options['colspan'] = 1;
         $options['target'] = $profile->getFormURL();
         $this->fields["id"] = $ID;
         $this->showFormHeader($options);
      }
      
      $effective_rights = ProfileRight::getProfileRights($ID, array('plugin_uninstall_use', 
         'plugin_uninstall_replace'));

      echo "<tr><th colspan='2' class='center b'>".sprintf(__('%1$s - %2$s'), self::getTypeName(),
         $profile->fields["name"])."</th></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".PluginUninstallUninstall::getTypeName()."</td><td>";
      Html::showCheckbox(array('name'    => '_plugin_uninstall_use[1_0]',
         'checked' => ($effective_rights['plugin_uninstall_use'] & READ),
         'id' => 'checkbox_read'
      ));
      echo " <label for='checkbox_read'>".__('Read')."</label><br>";
      
      Html::showCheckbox(array('name'    => '_plugin_uninstall_use[2_0]',
         'checked' => ($effective_rights['plugin_uninstall_use'] & (UPDATE)),
         'id' => 'checkbox_write'
      ));
      echo " <label for='checkbox_write'>".__('Write')."</label><br>";
      echo "</td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td>".PluginUninstallReplace::getTypeName()."</td><td>";
      Dropdown::showYesNo("_plugin_uninstall_replace", $effective_rights['plugin_uninstall_replace']);
      echo "</td></tr>";

      if ($canedit) {
         $options['candel'] = false;
         $this->showFormButtons($options);
      }
   }

   static function createFirstAccess($ID) {
      self::addDefaultProfileInfos($ID,
            array('plugin_uninstall_use'     => UPDATE + READ,
                  'plugin_uninstall_replace' => 1), true);
   }

   /**
    * Init profiles
    *
    **/
   
   static function translateARight($old_right) {
      switch ($old_right) {
         case '':
            return 0;
         case 'r' :
            return READ;
         case 'w':
            return UPDATE + READ;
         case '0':
         case '1':
            return $old_right;
   
         default :
            return 0;
      }
   }

   /**
    * @since 0.85
    * Migration rights from old system to the new one for one profile
    * @param $profiles_id the profile ID
    */
   static function migrateOneProfile($profiles_id) {
      global $DB;
      //Cannot launch migration if there's nothing to migrate...
      if (!TableExists('glpi_plugin_uninstall_profiles')) {
         return true;
      }
   
      foreach ($DB->request('glpi_plugin_uninstall_profiles',
                           "`profiles_id`='$profiles_id'") as $profile_data) {
   
         $matching = array('use'       => 'plugin_uninstall_use',
                           'replace'   => 'plugin_uninstall_replace');
         $current_rights = ProfileRight::getProfileRights($profiles_id, array_values($matching));
         foreach ($matching as $old => $new) {
            if (!isset($current_rights[$old])) {
               $query = "UPDATE `glpi_profilerights`
                  SET `rights`='".self::translateARight($profile_data[$old])."'
                  WHERE `name`='$new' AND `profiles_id`='$profiles_id'";
                  $DB->query($query);
            }
         }
      }
   }
   
   /**
    * Initialize profiles, and migrate it necessary
    */
   static function initProfile() {
      global $DB;
      $profile = new self();
      
      //Add new rights in glpi_profilerights table
      foreach (array('plugin_uninstall_use', 'plugin_uninstall_replace') as $field) {
         if (countElementsInTable("glpi_profilerights", "`name` = '".$field."'") == 0) {
            ProfileRight::addProfileRights(array($field));
         }
      }
   
      //Migration old rights in new ones
      foreach ($DB->request("SELECT `id` FROM `glpi_profiles`") as $prof) {
         self::migrateOneProfile($prof['id']);
      }
      foreach ($DB->request("SELECT *
                           FROM `glpi_profilerights`
                           WHERE `profiles_id`='".$_SESSION['glpiactiveprofile']['id']."'
                              AND `name` LIKE '%plugin_uninstall%'") as $prof) {
                                 $_SESSION['glpiactiveprofile'][$prof['name']] = $prof['rights'];
      }
   }
   
   static function removeRightsFromSession() {
      foreach (array('plugin_uninstall_use', 'plugin_uninstall_replace') as $field) {
         if (isset($_SESSION['glpiactiveprofile'][$field])) {
            unset($_SESSION['glpiactiveprofile'][$field]);
         }
      }
   }


   function getTabNameForItem(CommonGLPI $item, $withtemplate=0) {

      if ($item->getType() == 'Profile') {
         if ($item->getField('interface') == 'central') {
            return PluginUninstallUninstall::getTypeName();
         }
      }
      return '';
   }

   static function addDefaultProfileInfos($profiles_id, $rights, $drop_existing = false) {
      global $DB;
   
      $profileRight = new ProfileRight();
      foreach ($rights as $right => $value) {
         if (countElementsInTable('glpi_profilerights',
               "`profiles_id`='$profiles_id' AND `name`='$right'") && $drop_existing) {
               $profileRight->deleteByCriteria(array('profiles_id' => $profiles_id, 'name' => $right));
         }
         if (!countElementsInTable('glpi_profilerights',
               "`profiles_id`='$profiles_id' AND `name`='$right'")) {
               $myright['profiles_id'] = $profiles_id;
               $myright['name']        = $right;
               $myright['rights']      = $value;
               $profileRight->add($myright);
   
               //Add right to the current session
               $_SESSION['glpiactiveprofile'][$right] = $value;
         }
      }
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum=1, $withtemplate=0) {

      if ($item->getType() == 'Profile') {
         $ID = $item->getID();
         $prof = new self();
         
         self::addDefaultProfileInfos($ID,
               array('plugin_uninstall_use'     => 0,
                     'plugin_uninstall_replace' => 0));
         $prof->showForm($ID);
      }
      return true;
   }


   static function install($migration) {
      global $DB;

      // From 0.2 to 1.0.0
      $table = 'glpi_plugin_uninstallcomputer_profiles';
      if (TableExists($table)) {
         $migration->changeField($table, 'use', 'use', "char", array('value' => '0'));
         $migration->migrationOneTable($table);

         $query = "UPDATE `".$table."`
                   SET `use` = 'r'
                   WHERE `use` = '1'";
         $DB->queryOrDie($query, "change value use (1 to r) for ".$table);

         $migration->renameTable($table, 'glpi_plugin_uninstall_profiles');
      }


      $table = 'glpi_plugin_uninstall_profiles';
      // Plugin already installed
      if (TableExists($table)) {
         // From 1.0.0 to 1.3.0
         if (FieldExists($table, 'ID')) {
            $migration->changeField($table, 'ID', 'id', 'autoincrement');
            $migration->changeField($table, 'use', 'use', "varchar(1) DEFAULT ''");
         }

         // From 1.3.0 to 2.0.0
         if (!FieldExists($table, 'replace')) {
            $migration->addField($table, 'replace', "bool");
            $migration->migrationOneTable($table);
            // UPDATE replace access for current user
            $query = "UPDATE `glpi_plugin_uninstall_profiles` SET `replace` = 1 
             WHERE `id` = ".$_SESSION['glpiactiveprofile']['id'];
            $DB->query($query);
         }

      // plugin never installed
      } else {
         $query = "CREATE TABLE `".$table."` (
                    `id` int(11) NOT NULL DEFAULT '0',
                    `profile` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '0',
                    `use` varchar(1) DEFAULT '',
                    `replace` tinyint(1) NOT NULL default '0',
                    PRIMARY KEY (`id`)
                  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
         $DB->queryOrDie($query, $DB->error());
         self::createFirstAccess($_SESSION['glpiactiveprofile']['id']);
      }
      return true;
   }


   static function uninstall() {
      global $DB;

      $DB->query("DROP TABLE IF EXISTS `".getTableForItemType(__CLASS__)."`");
   }

}
