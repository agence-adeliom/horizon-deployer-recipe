<?php
/*
 * Déclaration qui n'est pas un pur fichier de données : roles:check doit la refuser sans jamais
 * l'exécuter (le fichier témoin ne doit pas apparaître).
 */

file_put_contents('/tmp/hdr-roles-execute', 'exécuté');

return array();
