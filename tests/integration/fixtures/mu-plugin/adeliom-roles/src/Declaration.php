<?php

namespace Adeliom\Roles;

defined('ABSPATH') || exit;

/**
 * Déclaration des rôles gérés, lue dans roles.php (voir le commentaire de ce fichier pour le format).
 *
 * Une déclaration invalide (fichier absent ou illisible, erreur de syntaxe, clé manquante, type inattendu)
 * n'est jamais appliquée : errors() la décrit et les accesseurs renvoient des valeurs neutres.
 */
final class Declaration
{
    private const KEYS = array('version', 'roles', 'expected', 'extra_roles_ui', 'default_extra_roles', 'debug_page');

    /** @var array */
    private $data;

    /** @var string[] */
    private $errors;

    /**
     * @param array    $data
     * @param string[] $errors
     */
    private function __construct(array $data, array $errors)
    {
        $this->errors = $errors;
        $this->data = $errors ? array(
            'version' => 0,
            'roles' => array(),
            'expected' => array('roles' => array(), 'caps' => array()),
            'extra_roles_ui' => false,
            'default_extra_roles' => array(),
            'debug_page' => false,
        ) : $data;
    }

    /**
     * Lit roles.php, pur fichier de données (return array(...)).
     *
     * @param string $path
     * @return Declaration
     */
    public static function fromFile(string $path): Declaration
    {
        $name = basename($path);
        if (!is_file($path) || !is_readable($path)) {
            return new self(array(), array("Fichier de déclaration introuvable ou illisible\u{00A0}: " . $path));
        }
        try {
            $data = (static function ($file) {
                return require $file;
            })($path);
        } catch (\Throwable $e) {
            return new self(array(), array(sprintf(
                "%s illisible\u{00A0}: %s (ligne %d)",
                $name,
                $e->getMessage(),
                $e->getLine()
            )));
        }
        if (!is_array($data)) {
            return new self(array(), array($name . ' doit renvoyer un tableau (return array(...)).'));
        }

        return self::fromArray($data);
    }

    /**
     * Construit une déclaration depuis un tableau (tests, ou contenu de roles.php déjà lu).
     *
     * @param array $data
     * @return Declaration
     */
    public static function fromArray(array $data): Declaration
    {
        return new self($data, self::validate($data));
    }

    /**
     * Erreurs de format d'une déclaration (liste vide si elle est valide).
     *
     * @param array $data
     * @return string[]
     */
    private static function validate(array $data): array
    {
        $errors = array();
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                $errors[] = "Clé manquante\u{00A0}: " . $key . '.';
            }
        }
        if ($errors) {
            return $errors;
        }

        if (!is_int($data['version']) || $data['version'] < 1) {
            $errors[] = "version doit être un entier positif (valeur actuelle\u{00A0}: " . self::show($data['version']) . ').';
        }

        if (!is_array($data['roles'])) {
            $errors[] = 'roles doit être un tableau.';
        } else {
            foreach ($data['roles'] as $slug => $role) {
                // PHP convertit une clé numérique ('2024') en entier : elle reste un slug valide.
                $slug = (string) $slug;
                $label = 'roles[' . $slug . ']';
                if ($slug === '' || !is_array($role)) {
                    $errors[] = $label . ' doit être un tableau, indexé par le slug du rôle.';
                    continue;
                }
                if (isset($role['name']) && !is_string($role['name'])) {
                    $errors[] = $label . '[name] doit être une chaîne.';
                }
                if (isset($role['caps']) && !self::isCapMap($role['caps'], false)) {
                    $errors[] = $label . '[caps] doit être un tableau cap => true ou false.';
                }
                if (isset($role['remove']) && !self::isStringList($role['remove'])) {
                    $errors[] = $label . '[remove] doit être une liste de caps.';
                }
            }
        }

        $expected = $data['expected'];
        if (!is_array($expected) || !isset($expected['roles'], $expected['caps'])) {
            $errors[] = 'expected doit contenir roles et caps.';
        } else {
            if (!self::isStringList($expected['roles'])) {
                $errors[] = 'expected[roles] doit être une liste de slugs.';
            }
            if (!is_array($expected['caps'])) {
                $errors[] = 'expected[caps] doit être un tableau.';
            } else {
                foreach ($expected['caps'] as $slug => $caps) {
                    if (!self::isCapMap($caps, true)) {
                        $errors[] = 'expected[caps][' . $slug . '] doit être un tableau cap => true, false ou null.';
                    }
                }
            }
        }

        if (!is_bool($data['extra_roles_ui'])) {
            $errors[] = 'extra_roles_ui doit valoir true ou false.';
        }
        if (!self::isStringList($data['default_extra_roles'])) {
            $errors[] = 'default_extra_roles doit être une liste de slugs.';
        }
        if (!is_bool($data['debug_page'])) {
            $errors[] = 'debug_page doit valoir true ou false.';
        }

        return $errors;
    }

    /**
     * @param mixed $value
     * @param bool  $nullable
     * @return bool
     */
    private static function isCapMap($value, bool $nullable): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $cap => $grant) {
            // Clé numérique ('7') convertie en entier par PHP : acceptée comme nom de cap.
            if ((string) $cap === '' || !(is_bool($grant) || ($nullable && $grant === null))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private static function isStringList($value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Valeur courte et lisible pour un message d'erreur.
     *
     * @param mixed $value
     * @return string
     */
    private static function show($value): string
    {
        return is_scalar($value) || $value === null ? var_export($value, true) : gettype($value);
    }

    /**
     * Version déclarée : à augmenter à chaque modification (0 si la déclaration est invalide).
     */
    public function version(): int
    {
        return (int) $this->data['version'];
    }

    /**
     * Rôles gérés : slug => array(name, caps, remove).
     */
    public function roles(): array
    {
        return $this->data['roles'];
    }

    /**
     * Empreinte attendue de la production : array(roles, caps).
     */
    public function expected(): array
    {
        return $this->data['expected'];
    }

    /**
     * Cases « Rôles supplémentaires » sur la fiche utilisateur.
     */
    public function extraRolesUi(): bool
    {
        return $this->data['extra_roles_ui'];
    }

    /**
     * Rôles supplémentaires donnés aux nouveaux comptes.
     */
    public function defaultExtraRoles(): array
    {
        return $this->data['default_extra_roles'];
    }

    /**
     * Page Outils › Rôles (Adeliom).
     */
    public function debugPage(): bool
    {
        return $this->data['debug_page'];
    }

    /**
     * Erreurs de la déclaration : liste vide si elle est valide.
     *
     * @return string[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * La déclaration est-elle valide ?
     */
    public function isValid(): bool
    {
        return !$this->errors;
    }
}
