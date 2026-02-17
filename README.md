# Selection Renamer

Application de bureau Windows (Tkinter) pour photographe.
Parse un email de selection de photos, retrouve les fichiers RAW
correspondants dans un dossier local et les renomme en ajoutant un suffixe.

## Prerequis

- **Python 3.10+** avec Tkinter (inclus par defaut sur Windows).
- Aucune dependance externe.

Pour verifier que Tkinter est disponible :

```bash
python -c "import tkinter; print('OK')"
```

## Installation

```bash
git clone <url-du-depot>
cd selection-renamer
```

Aucun `pip install` n'est necessaire (pas de dependances externes).

## Utilisation

```bash
python main.py
```

### Etapes dans l'application

1. **Dossier de travail** : selectionnez le dossier contenant vos fichiers RAW.
2. **Rating cible** : seuls les fichiers avec un rating >= a cette valeur seront traites (defaut : 5).
3. **Suffixe** : texte ajoute au nom du fichier (defaut : `_selec`).
4. **Extensions RAW** : extensions a rechercher, separees par des espaces (defaut : `.dng .cr2`).
5. **Inclure sous-dossiers** : cochez pour scanner recursivement.
6. **Renommer XMP** : cochez pour renommer aussi les fichiers `.xmp` associes.
7. **Contenu de l'email** : collez le texte de l'email de selection (bouton "Coller depuis le presse-papier" ou Ctrl+V dans la zone de texte).
8. **Previsualiser** : affiche les renommages prevus sans modifier les fichiers.
9. **Appliquer le renommage** : effectue les renommages apres confirmation.

### Formats d'email supportes

**Format 1 -- etoiles** (format principal) :
```
★★★★★  Alice_C_024.jpg
★★★★  Alice_C_038.jpg
```

**Format 2 -- galerie avec ID** :
```
Laure_Mime_010 (ID 80819): 5 - https://example.com/Laure_Mime_010.jpg
```

**Format 3 -- legacy** :
```
Romain-Fabre-8483.jpg - Rating: 5
```

### Statuts de resolution

| Statut             | Signification                                  |
|--------------------|------------------------------------------------|
| `ok`               | Fichier trouve, renommage possible             |
| `missing`          | Aucun fichier RAW correspondant                |
| `ambiguous`        | Plusieurs fichiers RAW pour le meme stem       |
| `already_suffixed` | Fichier deja renomme (doublon evite)           |

### Journal CSV

Chaque operation (previsualisation ou renommage) genere une ligne dans
`selection_renamer_log.csv` dans le dossier de travail, avec horodatage,
action, stem, rating, statut et fichiers concernes.

## Generation d'un executable Windows (.exe)

Installez PyInstaller :

```bash
pip install pyinstaller
```

Generez l'executable :

```bash
pyinstaller --onefile --windowed --name "SelectionRenamer" main.py
```

L'executable se trouve dans le dossier `dist/`.

### Options utiles de PyInstaller

- `--onefile` : un seul fichier `.exe` (plus simple a distribuer).
- `--windowed` : pas de fenetre console (mode graphique pur).
- `--icon=icon.ico` : ajouter une icone personnalisee.

## Depannage

### L'antivirus bloque l'executable

Les executables generes par PyInstaller sont parfois signales comme suspects
par les antivirus (faux positif). Solutions :

- Ajoutez une exception dans votre antivirus pour le fichier `.exe` ou le
  dossier `dist/`.
- Signez l'executable avec un certificat de signature de code si vous
  distribuez l'application.
- Utilisez `--onedir` au lieu de `--onefile` : certains antivirus sont moins
  agressifs avec un dossier qu'avec un unique `.exe` compresse.

### L'executable est volumineux

Un `.exe` PyInstaller `--onefile` inclut l'interpreteur Python complet.
Taille typique : 10-15 Mo. Pour reduire :

- Utilisez un environnement virtuel propre avec uniquement les modules
  necessaires avant de lancer PyInstaller.
- Ajoutez `--exclude-module` pour les modules inutiles (numpy, etc.).
- Utilisez UPX (compression d'executables) : `pyinstaller --onefile --upx-dir=/chemin/vers/upx`.

### Tkinter non disponible

Sur certaines distributions Linux, Tkinter n'est pas installe par defaut :

```bash
# Debian / Ubuntu
sudo apt install python3-tk

# Fedora
sudo dnf install python3-tkinter
```

Sur Windows et macOS, Tkinter est inclus dans l'installeur officiel de Python.
