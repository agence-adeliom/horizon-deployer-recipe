{
    "name": "integration/projet",
    "description": "Projet Bedrock minimal de la suite d'intégration de horizon-deployer-recipe",
    "type": "project",
    "license": "MIT",
    "repositories": [
        {
            "type": "path",
            "url": "/recipe",
            "options": {
                "symlink": true,
                "versions": {
                    "agence-adeliom/horizon-deployer-recipe": "1.9.99"
                }
            }
        }
    ],
    "require-dev": {
        "deployer/deployer": "__DEPLOYER_CONSTRAINT__",
        "agence-adeliom/horizon-deployer-recipe": "*"
    },
    "config": {
        "sort-packages": true
    }
}
