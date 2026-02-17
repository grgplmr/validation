"""Selection Renamer -- logique metier et interface Tkinter.

Application de bureau pour photographe : parse un email de selection,
retrouve les fichiers RAW correspondants et les renomme avec un suffixe.
"""

from __future__ import annotations

import csv
import os
import re
import tkinter as tk
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path
from tkinter import filedialog, messagebox, scrolledtext
from typing import Sequence


# ---------------------------------------------------------------------------
# Dataclasses
# ---------------------------------------------------------------------------


@dataclass(frozen=True)
class RatedItem:
    """Nom de fichier (stem) extrait d'un email avec sa note."""

    stem: str
    rating: int


@dataclass
class ResolveResult:
    """Resultat de la resolution d'un RatedItem sur le systeme de fichiers.

    Statuts possibles :
    - ``ok``              : fichier trouve, renommage possible
    - ``missing``         : aucun fichier RAW correspondant
    - ``ambiguous``       : plusieurs fichiers RAW pour le meme stem
    - ``already_suffixed``: fichier deja renomme (doublon evite)
    """

    item: RatedItem
    status: str
    matched_files: list[Path] = field(default_factory=list)
    new_names: list[Path] = field(default_factory=list)


# ---------------------------------------------------------------------------
# Parsing -- trois formats d'email
# ---------------------------------------------------------------------------

_RE_STARS = re.compile(r"^(\u2605+)\s+(.+)$")
_RE_GALLERY = re.compile(r"^(.+?)\s*\(ID\s+\d+\)\s*:\s*(\d+)")
_RE_LEGACY = re.compile(r"^(.+?)\s*-\s*Rating\s*:\s*(\d+)", re.IGNORECASE)


def _try_format_stars(line: str) -> RatedItem | None:
    """Format 1 : etoiles.  ``★★★★★  Alice_C_024.jpg``"""
    m = _RE_STARS.match(line)
    if m is None:
        return None
    rating = len(m.group(1))
    stem = Path(m.group(2).strip()).stem
    return RatedItem(stem=stem, rating=rating)


def _try_format_gallery(line: str) -> RatedItem | None:
    """Format 2 : galerie avec ID.  ``Stem (ID 80819): 5 - url``"""
    m = _RE_GALLERY.match(line)
    if m is None:
        return None
    stem = m.group(1).strip()
    rating = int(m.group(2))
    return RatedItem(stem=stem, rating=rating)


def _try_format_legacy(line: str) -> RatedItem | None:
    """Format 3 : legacy.  ``Filename.jpg - Rating: 5``"""
    m = _RE_LEGACY.match(line)
    if m is None:
        return None
    stem = Path(m.group(1).strip()).stem
    rating = int(m.group(2))
    return RatedItem(stem=stem, rating=rating)


def extract_rated_filenames(text: str) -> list[RatedItem]:
    """Parse le texte d'un email et extrait les fichiers notes.

    Gere trois formats (etoiles, galerie avec ID, legacy).
    Les stems sont dedupliques (insensible a la casse).
    """
    results: list[RatedItem] = []
    seen: set[str] = set()

    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue

        item = _try_format_stars(line)
        if item is None:
            item = _try_format_gallery(line)
        if item is None:
            item = _try_format_legacy(line)

        if item is not None:
            key = item.stem.lower()
            if key not in seen:
                seen.add(key)
                results.append(item)

    return results


# ---------------------------------------------------------------------------
# Scan du systeme de fichiers
# ---------------------------------------------------------------------------


def scan_raw_files(
    folder: Path,
    extensions: Sequence[str],
    include_subfolders: bool,
) -> list[Path]:
    """Retourne tous les fichiers dont le suffixe correspond a *extensions*.

    Les extensions sont comparees sans tenir compte de la casse.
    """
    ext_set = {
        e.lower() if e.startswith(".") else f".{e.lower()}" for e in extensions
    }
    results: list[Path] = []

    if include_subfolders:
        for root, _dirs, files in os.walk(folder):
            for fname in files:
                p = Path(root) / fname
                if p.suffix.lower() in ext_set:
                    results.append(p)
    else:
        try:
            for p in folder.iterdir():
                if p.is_file() and p.suffix.lower() in ext_set:
                    results.append(p)
        except OSError:
            pass

    return results


# ---------------------------------------------------------------------------
# Resolution : associer les items aux fichiers locaux
# ---------------------------------------------------------------------------


def resolve_files(
    items: list[RatedItem],
    raw_files: list[Path],
    suffix: str,
    target_rating: int,
    rename_xmp: bool,
) -> list[ResolveResult]:
    """Associe chaque *RatedItem* aux fichiers RAW locaux.

    Construit un index par stem (insensible a la casse) puis determine
    le statut de chaque item : ``ok``, ``missing``, ``ambiguous`` ou
    ``already_suffixed``.
    """
    # Index : stem minuscule -> liste de chemins
    index: dict[str, list[Path]] = {}
    for p in raw_files:
        index.setdefault(p.stem.lower(), []).append(p)

    suffix_lower = suffix.lower()
    results: list[ResolveResult] = []

    for item in items:
        if item.rating < target_rating:
            continue

        key = item.stem.lower()
        matches = index.get(key, [])
        suffixed_matches = index.get(key + suffix_lower, [])

        # Aucun fichier trouve (ni brut ni deja renomme)
        if not matches and not suffixed_matches:
            results.append(ResolveResult(item=item, status="missing"))
            continue

        # Seule la version deja suffixee existe
        if not matches and suffixed_matches:
            results.append(
                ResolveResult(
                    item=item,
                    status="already_suffixed",
                    matched_files=suffixed_matches,
                )
            )
            continue

        # Plusieurs fichiers bruts pour le meme stem
        if len(matches) > 1:
            results.append(
                ResolveResult(
                    item=item, status="ambiguous", matched_files=matches
                )
            )
            continue

        # Un seul fichier brut : renommage possible
        matched = matches[0]
        new_path = matched.with_name(matched.stem + suffix + matched.suffix)
        all_matched: list[Path] = [matched]
        all_new: list[Path] = [new_path]

        if rename_xmp:
            xmp_path = matched.with_suffix(".xmp")
            try:
                if xmp_path.exists():
                    all_matched.append(xmp_path)
                    all_new.append(
                        xmp_path.with_name(matched.stem + suffix + ".xmp")
                    )
            except OSError:
                pass

        results.append(
            ResolveResult(
                item=item,
                status="ok",
                matched_files=all_matched,
                new_names=all_new,
            )
        )

    return results


# ---------------------------------------------------------------------------
# Renommage effectif
# ---------------------------------------------------------------------------


def apply_renames(
    resolve_results: list[ResolveResult],
) -> list[tuple[Path, Path, str | None]]:
    """Renomme les fichiers sur disque pour les resultats au statut ``ok``.

    Retourne une liste de tuples ``(ancien, nouveau, erreur_ou_None)``.
    """
    log: list[tuple[Path, Path, str | None]] = []
    for r in resolve_results:
        if r.status != "ok":
            continue
        for old, new in zip(r.matched_files, r.new_names):
            try:
                old.rename(new)
                log.append((old, new, None))
            except OSError as exc:
                log.append((old, new, str(exc)))
    return log


# ---------------------------------------------------------------------------
# Journal CSV
# ---------------------------------------------------------------------------


def write_csv_log(
    log_path: Path,
    action: str,
    resolve_results: list[ResolveResult],
) -> None:
    """Ajoute une entree horodatee au fichier CSV de journal."""
    timestamp = datetime.now().isoformat(timespec="seconds")
    file_exists = log_path.exists()
    try:
        with open(log_path, "a", newline="", encoding="utf-8") as fh:
            writer = csv.writer(fh)
            if not file_exists:
                writer.writerow(
                    ["timestamp", "action", "stem", "rating", "status", "files"]
                )
            for r in resolve_results:
                files_str = "; ".join(str(p) for p in r.matched_files)
                writer.writerow(
                    [
                        timestamp,
                        action,
                        r.item.stem,
                        r.item.rating,
                        r.status,
                        files_str,
                    ]
                )
    except OSError:
        pass  # Non critique : ne pas planter pour un log


# ---------------------------------------------------------------------------
# Auto-test du parser
# ---------------------------------------------------------------------------


def parser_self_test() -> bool:
    """Teste les trois formats d'email.

    Retourne ``True`` si tous les stems attendus sont extraits correctement.
    A lancer au demarrage ; le resultat est affiche dans la console.
    """
    all_ok = True

    # Format 1 -- etoiles
    text1 = (
        "\u2605\u2605\u2605\u2605\u2605  Alice_C_024.jpg\n"
        "\u2605\u2605\u2605\u2605  Alice_C_038.jpg\n"
    )
    items1 = extract_rated_filenames(text1)
    expected1 = [("Alice_C_024", 5), ("Alice_C_038", 4)]
    actual1 = [(i.stem, i.rating) for i in items1]
    if actual1 != expected1:
        print(f"[SELF-TEST FAIL] Format 1 : attendu {expected1}, obtenu {actual1}")
        all_ok = False

    # Format 2 -- galerie avec ID
    text2 = (
        "Laure_Mime_010 (ID 80819): 5 - "
        "https://example.com/Laure_Mime_010.jpg\n"
    )
    items2 = extract_rated_filenames(text2)
    expected2 = [("Laure_Mime_010", 5)]
    actual2 = [(i.stem, i.rating) for i in items2]
    if actual2 != expected2:
        print(f"[SELF-TEST FAIL] Format 2 : attendu {expected2}, obtenu {actual2}")
        all_ok = False

    # Format 3 -- legacy
    text3 = "Romain-Fabre-8483.jpg - Rating: 5\n"
    items3 = extract_rated_filenames(text3)
    expected3 = [("Romain-Fabre-8483", 5)]
    actual3 = [(i.stem, i.rating) for i in items3]
    if actual3 != expected3:
        print(f"[SELF-TEST FAIL] Format 3 : attendu {expected3}, obtenu {actual3}")
        all_ok = False

    return all_ok


# ---------------------------------------------------------------------------
# Interface Tkinter
# ---------------------------------------------------------------------------


class SelectionRenamerApp:
    """Fenetre principale de l'application Selection Renamer."""

    def __init__(self, root: tk.Tk) -> None:
        self.root = root
        root.title("Selection Renamer")
        root.resizable(True, True)
        self._last_results: list[ResolveResult] = []
        self._build_ui()

    # -- Construction de l'interface ----------------------------------------

    def _build_ui(self) -> None:
        pad: dict[str, int] = {"padx": 6, "pady": 3}
        row = 0

        # 1. Dossier de travail
        tk.Label(self.root, text="Dossier de travail :").grid(
            row=row, column=0, sticky="w", **pad
        )
        self.var_folder = tk.StringVar()
        tk.Entry(self.root, textvariable=self.var_folder, width=50).grid(
            row=row, column=1, sticky="ew", **pad
        )
        tk.Button(
            self.root, text="Parcourir\u2026", command=self._browse_folder
        ).grid(row=row, column=2, **pad)
        row += 1

        # 2. Rating cible
        tk.Label(self.root, text="Rating cible :").grid(
            row=row, column=0, sticky="w", **pad
        )
        self.var_rating = tk.StringVar(value="5")
        tk.Entry(self.root, textvariable=self.var_rating, width=10).grid(
            row=row, column=1, sticky="w", **pad
        )
        row += 1

        # 3. Suffixe
        tk.Label(self.root, text="Suffixe :").grid(
            row=row, column=0, sticky="w", **pad
        )
        self.var_suffix = tk.StringVar(value="_selec")
        tk.Entry(self.root, textvariable=self.var_suffix, width=20).grid(
            row=row, column=1, sticky="w", **pad
        )
        row += 1

        # 4. Extensions RAW
        tk.Label(self.root, text="Extensions RAW :").grid(
            row=row, column=0, sticky="w", **pad
        )
        self.var_extensions = tk.StringVar(value=".dng .cr2")
        tk.Entry(self.root, textvariable=self.var_extensions, width=30).grid(
            row=row, column=1, sticky="w", **pad
        )
        row += 1

        # 5. Inclure sous-dossiers
        self.var_subfolders = tk.BooleanVar(value=False)
        tk.Checkbutton(
            self.root,
            text="Inclure les sous-dossiers",
            variable=self.var_subfolders,
        ).grid(row=row, column=0, columnspan=2, sticky="w", **pad)
        row += 1

        # 6. Renommer XMP
        self.var_xmp = tk.BooleanVar(value=False)
        tk.Checkbutton(
            self.root,
            text="Renommer aussi les fichiers .xmp",
            variable=self.var_xmp,
        ).grid(row=row, column=0, columnspan=2, sticky="w", **pad)
        row += 1

        # 7. Zone de texte email + bouton Coller
        tk.Label(self.root, text="Contenu de l'email :").grid(
            row=row, column=0, sticky="nw", **pad
        )
        self.txt_email = scrolledtext.ScrolledText(
            self.root, width=60, height=8
        )
        self.txt_email.grid(row=row, column=1, sticky="ew", **pad)
        tk.Button(
            self.root,
            text="Coller depuis le presse-papier",
            command=self._paste_clipboard,
        ).grid(row=row, column=2, **pad)
        row += 1

        # 8. Bouton Previsualiser
        tk.Button(
            self.root, text="Pr\u00e9visualiser", command=self._preview
        ).grid(row=row, column=0, columnspan=3, sticky="ew", **pad)
        row += 1

        # 9. Bouton Appliquer
        tk.Button(
            self.root,
            text="Appliquer le renommage",
            command=self._apply,
        ).grid(row=row, column=0, columnspan=3, sticky="ew", **pad)
        row += 1

        # 10. Journal scrollable
        tk.Label(self.root, text="Journal :").grid(
            row=row, column=0, sticky="nw", **pad
        )
        self.txt_log = scrolledtext.ScrolledText(
            self.root, width=80, height=18, state="disabled"
        )
        self.txt_log.grid(
            row=row, column=1, columnspan=2, sticky="nsew", **pad
        )
        row += 1

        # Rendre la grille extensible
        self.root.columnconfigure(1, weight=1)
        self.root.rowconfigure(row - 1, weight=1)

    # -- Utilitaires --------------------------------------------------------

    def _log(self, message: str) -> None:
        """Ajoute une ligne au journal de l'interface."""
        self.txt_log.configure(state="normal")
        self.txt_log.insert("end", message + "\n")
        self.txt_log.see("end")
        self.txt_log.configure(state="disabled")

    def _browse_folder(self) -> None:
        path = filedialog.askdirectory()
        if path:
            self.var_folder.set(path)

    def _paste_clipboard(self) -> None:
        try:
            text = self.root.clipboard_get()
            self.txt_email.delete("1.0", "end")
            self.txt_email.insert("1.0", text)
        except tk.TclError:
            messagebox.showwarning(
                "Presse-papier", "Le presse-papier est vide."
            )

    def _get_params(
        self,
    ) -> tuple[Path, int, str, list[str], bool, bool] | None:
        """Valide et retourne les parametres de l'interface, ou None si erreur."""
        folder_str = self.var_folder.get().strip()
        if not folder_str:
            messagebox.showerror(
                "Erreur", "Veuillez s\u00e9lectionner un dossier de travail."
            )
            return None
        folder = Path(folder_str)
        if not folder.is_dir():
            messagebox.showerror(
                "Erreur", f"Le dossier n'existe pas :\n{folder}"
            )
            return None

        try:
            target_rating = int(self.var_rating.get())
        except ValueError:
            messagebox.showerror(
                "Erreur", "Le rating cible doit \u00eatre un nombre entier."
            )
            return None

        suffix = self.var_suffix.get().strip()
        if not suffix:
            messagebox.showerror(
                "Erreur", "Le suffixe ne peut pas \u00eatre vide."
            )
            return None

        extensions = self.var_extensions.get().split()
        if not extensions:
            messagebox.showerror(
                "Erreur", "Indiquez au moins une extension RAW."
            )
            return None

        return (
            folder,
            target_rating,
            suffix,
            extensions,
            self.var_subfolders.get(),
            self.var_xmp.get(),
        )

    # -- Actions principales ------------------------------------------------

    def _preview(self) -> None:
        """Previsualise le renommage sans modifier les fichiers."""
        params = self._get_params()
        if params is None:
            return
        folder, target_rating, suffix, extensions, subfolders, xmp = params

        email_text = self.txt_email.get("1.0", "end")
        items = extract_rated_filenames(email_text)
        if not items:
            self._log("Aucun fichier not\u00e9 trouv\u00e9 dans le texte coll\u00e9.")
            return

        self._log(
            f"--- Pr\u00e9visualisation ({len(items)} fichier(s) dans l'email) ---"
        )

        raw_files = scan_raw_files(folder, extensions, subfolders)
        self._log(f"Fichiers RAW dans le dossier : {len(raw_files)}")

        results = resolve_files(items, raw_files, suffix, target_rating, xmp)
        self._last_results = results

        for r in results:
            if r.status == "ok":
                for old, new in zip(r.matched_files, r.new_names):
                    self._log(f"  [OK] {old.name} -> {new.name}")
            elif r.status == "missing":
                self._log(
                    f"  [MANQUANT] {r.item.stem} -- aucun fichier RAW trouv\u00e9"
                )
            elif r.status == "ambiguous":
                names = ", ".join(p.name for p in r.matched_files)
                self._log(
                    f"  [AMBIGU] {r.item.stem} -- plusieurs fichiers : {names}"
                )
            elif r.status == "already_suffixed":
                self._log(
                    f"  [DEJA RENOMME] {r.matched_files[0].name}"
                )

        ok_count = sum(1 for r in results if r.status == "ok")
        self._log(f"R\u00e9sum\u00e9 : {ok_count} fichier(s) pr\u00eat(s) a renommer.\n")

        # Journal CSV
        log_path = folder / "selection_renamer_log.csv"
        write_csv_log(log_path, "preview", results)

    def _apply(self) -> None:
        """Applique le renommage apres confirmation."""
        if not self._last_results:
            messagebox.showinfo(
                "Info", "Lancez d'abord une pr\u00e9visualisation."
            )
            return

        ok_count = sum(1 for r in self._last_results if r.status == "ok")
        if ok_count == 0:
            messagebox.showinfo("Info", "Aucun fichier a renommer.")
            return

        confirm = messagebox.askyesno(
            "Confirmation",
            f"Renommer {ok_count} fichier(s) ?\n"
            "Cette op\u00e9ration est irr\u00e9versible.",
        )
        if not confirm:
            return

        self._log("--- Application du renommage ---")
        rename_log = apply_renames(self._last_results)

        for old, new, error in rename_log:
            if error is None:
                self._log(f"  [OK] {old.name} -> {new.name}")
            else:
                self._log(f"  [ERREUR] {old.name} -- {error}")

        success = sum(1 for _, _, e in rename_log if e is None)
        fail = sum(1 for _, _, e in rename_log if e is not None)
        self._log(f"Termin\u00e9 : {success} renomm\u00e9(s), {fail} erreur(s).\n")

        # Journal CSV
        folder_str = self.var_folder.get().strip()
        if folder_str:
            log_path = Path(folder_str) / "selection_renamer_log.csv"
            write_csv_log(log_path, "apply", self._last_results)

        self._last_results = []
