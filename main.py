"""Selection Renamer -- point d'entree de l'application."""

import tkinter as tk

from selection_renamer_app import SelectionRenamerApp, parser_self_test


def main() -> None:
    """Lance l'application Selection Renamer."""
    # Auto-test du parser au demarrage (resultat dans la console uniquement)
    if parser_self_test():
        print("[SELF-TEST] OK -- les 3 formats d'email sont correctement parses.")
    else:
        print("[SELF-TEST] ECHEC -- verifiez extract_rated_filenames().")

    root = tk.Tk()
    SelectionRenamerApp(root)
    root.mainloop()


if __name__ == "__main__":
    main()
