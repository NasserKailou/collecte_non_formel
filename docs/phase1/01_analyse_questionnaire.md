# Phase 1 — Analyse du questionnaire

**Référence :** `Questionnaire_EFTP_Non_Formel_do30 12 2025.xlsx` et `.pdf` (version du 30/12/2025, identiques — le PDF est la version imprimée faisant foi).
**Émetteur :** Ministère de l'Enseignement et de la Formation Techniques et Professionnels — Direction des Statistiques et de la Digitalisation (DSD/ME/FT/P).
**Opération :** Recensement scolaire 2025-2026, EFTP non formels.

**Méthode :** extraction intégrale du classeur (871 cellules non vides, cellules fusionnées, lignes/colonnes masquées, feuille masquée, zones de texte, noms définis), puis confrontation au PDF imprimé. Le classeur ne contient ni formule ni validation de données : c'est un formulaire papier (`q` = case à cocher en police Wingdings).

> Une version antérieure (`Questionnaire_Non_Formel corrigé13Mai2024.xlsx`, recensement 2023-2024) a d'abord été analysée puis retirée par le client. Les différences sont résumées au §7 : elles justifient le **versionnement du questionnaire** dès la première livraison.

---

## 1. Structure physique

| Feuille | État | Contenu | Remarques |
|---|---|---|---|
| Page 1 | visible | En-tête + **Section I** (1.1 → 1.16) | Logo. Libellé « Recensement scolaire 2025-2026 » codé en dur |
| Page 2 | visible | **Section II** (2.1 → 2.20) | Colonnes N–Q et X masquées : options non imprimées (§4.3) |
| Page 3 | visible | **Section III** (tableau de 5 formateurs) | 5 lignes fixes → tableau dynamique |
| Page 4 | visible | **Section IV** (4.1, 4.2, 4.3) + **Section V** (observations, identification) | Lignes 66–133 masquées et vides |
| Page 5 | **masquée**, non imprimée | Ancien bloc observations/signatures | 4 zones de texte « BURKINA FASO » : résidu de gabarit. Ignorée |

Contenu fonctionnel : **5 sections** (I à V) sur 4 pages imprimées.

---

## 2. Dictionnaire des variables

Convention : `Qx_y[_z]` = numérotation du questionnaire (corrigée lorsqu'elle est fautive, §4). Les options gardent leur lettre (`a`, `b`, …) comme code stable.
Types : `TXT`, `INT` (entier ≥ 0), `DATE` (jj/mm/aaaa), `SC` choix unique, `MC` choix multiple, `YN` Oui/Non, `GPS`, `TEL` (8 chiffres). Obligation : **O** obligatoire, **C** conditionnelle, **F** facultative, **calc** calculée.

### Section I — Localisation et identification de la structure

| Code | Libellé | Type | Oblig. | Modalités / contrôle | Condition |
|---|---|---|---|---|---|
| Q1_1 | Nom de la structure | TXT | O | 3–150 car. ; pré-rempli depuis le référentiel | — |
| Q1_2 | Code | TXT | O | 10 chiffres ; pré-rempli, verrouillé | — |
| Q1_3 | Dispose d'un document administratif ? | YN | O | | — |
| Q1_3_1 | Ministère de tutelle | TXT | C | | Q1_3 = Oui |
| Q1_3_2 | Type du document administratif | TXT | C | nomenclature à constituer (arrêté, autorisation, récépissé…) | Q1_3 = Oui |
| Q1_3_3 | Références du document | TXT | C | | Q1_3 = Oui |
| Q1_4 | Date d'autorisation de création / début d'exercice | DATE | O | ≤ aujourd'hui | — |
| Q1_5 | Date d'ouverture | DATE | O | ≤ aujourd'hui ; avertissement si < Q1_4 | — |
| Q1_6_1 | Région | SC (réf.) | O | référentiel territorial | — |
| Q1_6_2 | Département | SC (réf.) | O | filtré par région | — |
| Q1_6_3 | Commune | SC (réf.) | O | filtré par département | — |
| Q1_6_4 | Quartier/village | TXT/réf. | O | liste si disponible, sinon saisie libre | — |
| Q1_7 | Zone | SC | O | a Urbaine, b Rurale | — |
| Q1_8 | Statut | SC | O | a Public, b Privé | — |
| Q1_9 | Type de structure | SC | O | a SFMA (SIFA), b CFA, c Atelier, d SAA, e Foyer, f Groupement, g Garage, h Coopérative/Association, i Cabinet de formation, j Plateforme d'orientation, k Autre | — |
| Q1_9_autre | Autre structure (préciser) | TXT | C | | Q1_9 = k |
| Q1_10 | Source(s) de financement | MC (proposé, A-01) | O | a État, b PTF, c FAFPA, d EPA, e ONG, f Associations, g Fonds propres, h Autres | — |
| Q1_10_autre | Autres (préciser) | TXT | C | | h ∈ Q1_10 |
| Q1_11 | Régime | SC | O | a Internat, b Externat | — |
| Q1_12 | Délivre des attestations en fin de formation ? | YN | O | | — |
| Q1_13 | Type d'accompagnement | **MC** (explicite) | C | a Espèces, b Matériel et outillage technique, c Renforcement des capacités techniques, d Placement, e Autres | **Q1_9 = j (plateforme d'orientation)** — « réservée uniquement aux plateformes » |
| Q1_13_autre | Autres (préciser) | TXT | C | | e ∈ Q1_13 |
| Q1_14 | La formation est-elle payante ? | YN | O | | — |
| Q1_14_1 | Mode de paiement | MC (proposé) | C | a Espèces, b Chèque, c En ligne, d En nature | Q1_14 = Oui |
| Q1_15 / Q1_16 | Longitude / Latitude | GPS | O* | capture GPS + précision, horodatage, état. *Si GPS indisponible : motif obligatoire | — |

### Section II — Environnement d'apprentissage

**2.1 Effectif des apprentis par durée, selon le secteur et le sexe**
Grille : lignes durée (≤ 3 mois, 4–9 mois, > 9 mois) × 3 secteurs (Primaire/Agricole, Secondaire/Industriel, Tertiaire/Service) × (M, F, Total).
→ `Q2_1[durée][secteur][M|F]` ; Total = calc. **Strictement redondante avec la Section IV** (D-01).

**2.2 Nombre de jeunes accompagnés par durée, selon le sexe** (hors formation reçue : orientation et insertion professionnelle)
Grille : lignes durée × (Jeunes inscrits, **dont** placés, **dont** outillés [espèces, nature ou kits]) × (M, F, Total). **Pas de ventilation par secteur.**
→ `Q2_2[durée][inscrits|places|outilles][M|F]` ; Total = calc.

| Code | Libellé | Type | Oblig. | Modalités / contrôle | Condition |
|---|---|---|---|---|---|
| Q2_3 | Alimentée en électricité ? | YN | O | | |
| Q2_3_1 | Principale source | SC | C | a Groupe électrogène, b Panneau solaire, c Réseau électrique | Q2_3 = Oui |
| Q2_4 | Point d'eau potable ? | YN | O | | |
| Q2_4_1 | Principale source | SC | C | a Robinet, b AEP, c Forage, d Puits cimenté | Q2_4 = Oui |
| Q2_5 | Clôture ? | YN | O | | |
| Q2_6 | Infirmerie fonctionnelle ? | YN | O | | |
| Q2_7 | Boîte à pharmacie ? | YN | O | | |
| Q2_8 | Dépotoir ? | YN | O | | |
| Q2_8_1 | Nombre de dépotoirs | INT | C | 1–999 | Q2_8 = Oui |
| Q2_9 | Rampes pour personnes handicapées ? | YN | O | | |
| Q2_10 | Cour de récréation ? | YN | O | | |
| Q2_11 | Latrines fonctionnelles ? | YN | O | | |
| Q2_11_1 | Nombre total de latrines | INT | C | 1–99 | Q2_11 = Oui |
| Q2_11_2_a | dont fonctionnelles — apprenants (M + F) | INT | C | ≤ Q2_11_1 | Q2_11 = Oui |
| Q2_11_2_b | dont fonctionnelles — filles uniquement | INT | C | ≤ Q2_11_1 | Q2_11 = Oui |
| Q2_11_2_c | dont fonctionnelles — personnel | INT | C | ≤ Q2_11_1 | Q2_11 = Oui |
| Q2_11_2_d | dont fonctionnelles — handicapés | INT | C | ≤ Q2_11_1 | Q2_11 = Oui |
| Q2_12 | Bibliothèque ? | YN | O | | |
| Q2_13 | Connexion Internet ? | YN | O | | |
| Q2_13_1 | Type de connexion | MC | C | a ADSL, b Liaison satellite, c Connexion mobile | Q2_13 = Oui |
| Q2_13_2 | Qui a accès à Internet ? *(numérotée « 2.14.2 » à tort)* | MC | C | a Apprentis, b Formateurs, c Personnel administratif, d Externe | Q2_13 = Oui |
| Q2_14 | Terrain de sport ? | YN | O | | |
| Q2_14_1 | Type de sport | MC | C | a Football, b Handball, c Basketball, d Volleyball, e Dojo, f Ring, g Autres | Q2_14 = Oui |
| Q2_14_1_autre | Autres (préciser) | TXT | C | | g ∈ Q2_14_1 |
| Q2_14_2 | Plateau technique ? | YN | C | | Q2_14 = Oui |
| Q2_15 | Aménagement paysager ? | YN | O | | |
| Q2_16 | Parking auto/moto ? | YN | O | | |
| Q2_17 | Dispositif de lavage des mains ? | YN | O | | |
| Q2_18 | Dispositif de collecte des ordures ? | YN | O | | |
| Q2_19_H / _F / _T | Nombre de maîtres artisans/formateurs (Homme, Femme, Total) | INT / calc | O | 0–99 ; **cohérence avec la Section III** (D-08, R-COH-04) | |
| Q2_20_H / _F / _T | Jeunes apprentis accompagnés par le FAFPA (Homme, Femme, Total) | INT / calc | O | 0–99 (gabarit 2 chiffres ; voir A-07) | |

### Section III — Maîtres artisans / formateurs

Tableau dynamique `trainers[]` (une ligne par formateur ; le papier en prévoit 5) :

| Code | Colonne | Type | Oblig. | Condition / contrôle |
|---|---|---|---|---|
| T_NUM | Numéro | INT auto | — | séquentiel |
| T_NOM | Nom et prénom | TXT | O | |
| T_SEXE | Sexe | SC | O | Homme / Femme |
| T_GRADE | Grade | SC | O | Maître artisan / Formateur |
| T_FI | Formation initiale | YN | O | |
| T_NB_FC | Nombre de formations continues suivies | INT | O | 0–99 |
| T_CERT | Qualification certifiée ? | YN | O | |
| T_CERT_NOM | Nom du dernier certificat obtenu | TXT | C | T_CERT = Oui |
| T_CERT_NIV | Niveau du dernier certificat | TXT/SC | C | T_CERT = Oui (nomenclature recommandée, A-05) |
| T_CERT_STRUCT | Structure certifiante | TXT | C | T_CERT = Oui |
| T_CERT_AN | Année de certification | INT | C | T_CERT = Oui ; 1950 ≤ année ≤ année courante |
| T_NAT | Nationalité | SC | O | Nigérien / UEMOA / Autre |
| T_SUIVI | Suivi technico-pédagogique reçu ? | YN | O | |

### Section IV — Apprentis dans les différents secteurs

Trois tableaux identiques : 4.1 Primaire/Agricole, 4.2 Secondaire/Industriel, 4.3 Tertiaire/Service.

**Lignes métiers** (dynamiques ; 5 lignes par secteur sur le papier) :

| Code | Colonne | Type | Oblig. | Contrôle |
|---|---|---|---|---|
| M_METIER | Métier | TXT/réf. | O | nomenclature des métiers par secteur + « autre (préciser) » (A-06) ; pas de doublon métier + durée dans un secteur |
| M_DUREE | Durée d'apprentissage | SC | O | ≤ 3 mois / 4–9 mois / > 9 mois |
| M_P{1,2,3}_{H,F} | Effectifs Promotion I, II, III × Homme, Femme | INT | O (0 accepté) | 0–999 (gabarit 3 chiffres) |

**Lignes de synthèse par secteur** (par Promotion × Sexe, **pas par métier**) :

| Code | Ligne | Type | Contrôle |
|---|---|---|---|
| S_TOTAL | Total | calc | Σ des lignes métiers, non saisissable |
| S_HAND | Dont handicapés | INT | ≤ Total de la cellule (bloquant) |
| S_REF | Dont réfugiés | INT | ≤ Total (bloquant) |
| S_RET | Dont retournés | INT | ≤ Total (bloquant) |
| S_DEP | Dont déplacés | INT | ≤ Total (bloquant) ; S_REF + S_RET + S_DEP ≤ Total (avertissement) |

> **Écart avec le prompt maître :** le prompt demande handicapés/réfugiés/retournés/déplacés **par métier** ; le questionnaire officiel les demande **par secteur × promotion × sexe**. Nous suivons le questionnaire. Passer au niveau métier relèverait d'une nouvelle version du questionnaire, à décider par la DSD.

### Section V — Observations et identification

| Code | Libellé | Type | Oblig. |
|---|---|---|---|
| Q5_OBS | Observations éventuelles | TXT long | F |
| Q5_DATE | Date de remplissage | DATE | O — pré-remplie ; ≤ aujourd'hui et dans la période de la campagne |
| Q5_REP_NOM | Nom et prénom du répondant | TXT | O |
| Q5_REP_TEL | Téléphone du répondant | TEL | O |
| Q5_CHEF_NOM | Nom et prénom du chef de la structure | TXT | O |
| Q5_CHEF_TEL | Téléphone du chef | TEL | F |
| Q5_ENQ | Nom et prénom de l'enquêteur | auto | **Rempli automatiquement** depuis le compte de l'agent connecté |
| Q5_SUP | Nom et prénom du superviseur | auto | **Rempli automatiquement** depuis la hiérarchie de supervision (superviseur valideur enregistré à la validation) |
| Q5_VISA | Signature et cachet (répondant, chef) | — | Remplacé par une case « fiche papier signée et cachetée » + **photo facultative** de la fiche signée (D-06) |

---

## 3. Tableaux à lignes dynamiques

| Tableau | Papier | Numérique | Règle |
|---|---|---|---|
| Formateurs (III) | 5 lignes | 0..n | effectifs par sexe = Q2_19 (D-08) |
| Métiers 4.1 / 4.2 / 4.3 | 5 lignes chacun | 0..n par secteur | au moins un métier dans au moins un secteur (bloquant, sauf observation justificative) |

---

## 4. Incohérences et ambiguïtés relevées

### 4.1 Règles de saut

Contrairement à la version 2024, **tous les renvois de la version 2025 sont cohérents** : un « Non » mène à la question de même niveau suivante (1.3→1.4, 2.3→2.4, 2.4→2.5, 2.8→2.9, 2.11→2.12, 2.13→2.14, 2.14→2.15).
**Règle implémentée :** un « Non » masque uniquement les sous-questions de la question filtre.

Saut implicite supplémentaire : **1.13 n'est posée qu'aux plateformes d'orientation** (Q1_9 = j).

### 4.2 Numérotation et libellés

| Réf. | Constat | Correction |
|---|---|---|
| N-01 | « 2.14.2 Qui a accès à l'Internet ? » placée sous 2.13 → doublon avec 2.14.2 (plateau technique) | **2.13.2** |
| N-02 | 4.3 utilise « M/F », 4.1 et 4.2 « Homme/Femme » | même variable, libellé harmonisé |
| N-03 | Ordre et libellés des types : « SFMA (SIFA) » | code `a`, libellé conservé |
| N-04 | Coquilles : « Ministére », « debut », « Regime », « LATTITUDE », « prénon », « fotmateur », « réçu », « enqueteur », « paysagé », « Basketba » tronqué dans le PDF, « b) Liaison satellite » décalé dans le PDF | corrigées à l'affichage, sans impact sur les codes |
| N-05 | En-tête « Recensement scolaire 2025-2026 » codé en dur | paramétré par campagne |
| N-06 | Titre de 1.9 « formation professionnelle non formelle » alors que l'opération porte sur « l'EFTP non formel » | libellé conservé |

### 4.3 Résidus de gabarit (non imprimés, exclus)

| Réf. | Constat | Traitement |
|---|---|---|
| G-01 | Feuille « Page 5 » masquée (ancien bloc signatures, mention du Directeur départemental) | ignorée |
| G-02 | 4 zones de texte « BURKINA FASO — Ministère de l'Enseignement de Base » | ignorées (à signaler à la DSD pour nettoyer le gabarit) |
| G-03 | 2.4.1 : option « e) Autres » et case orpheline en colonnes masquées | **exclue** (absente du PDF imprimé) |
| G-04 | 2.5 : option « c) Apprentissage » et cases orphelines en colonnes masquées | **exclue** (absente du PDF) |

### 4.4 Ambiguïtés à faire trancher par la DSD

| Réf. | Ambiguïté | Proposition par défaut |
|---|---|---|
| A-01 | 1.10 (financement) et 1.14.1 (paiement) : choix unique ou multiple ? | **Multiple** |
| A-02 | **2.1 double la Section IV** (durée × secteur × sexe = Σ métiers) | **2.1 calculée depuis la Section IV**, en lecture seule (D-01) |
| A-03 | **2.19 double la Section III** (nombre de formateurs H/F = lignes du tableau) | **2.19 calculée depuis la Section III**, en lecture seule (D-08) |
| A-04 | 2.11 demande « latrines **fonctionnelles** ? » puis « nombre total » puis « **dont** fonctionnelles » | Q2_11 compris comme « dispose de latrines » ; chaque catégorie ≤ total (bloquant) ; la somme des catégories peut dépasser le total (chevauchement possible : filles ⊂ apprenants) → avertissement seulement |
| A-05 | Niveau du dernier certificat : texte libre | nomenclature fermée recommandée (CQP, BQP, CAP, BEP, BT, autre) — à valider |
| A-06 | Métiers : texte libre | nomenclature des métiers par secteur + « autre (préciser) », pour des statistiques exploitables — liste à fournir |
| A-07 | 2.20 « accompagnés par le FAFPA » : sous-ensemble de 2.2 (inscrits) ? gabarit de 2 chiffres (≤ 99) | pas de contrôle bloquant ; avertissement si 2.20 > total inscrits 2.2 ; saisie limitée à 999 pour ne pas bloquer un cas réel |
| A-08 | 2.2 : « placés » et « outillés » sont-ils exclusifs ? | chacun ≤ inscrits (bloquant) ; placés + outillés > inscrits = avertissement |
| A-09 | Statut : la modalité « semi-public » a disparu ; régime : pas de « mixte » | suivre le questionnaire ; signaler à la DSD |
| A-10 | Dates 1.4/1.5 : jour/mois souvent inconnus sur le terrain | saisie possible de l'**année seule** (jour/mois facultatifs), avec indicateur de précision |

---

## 5. Règles de contrôle retenues

Définies une seule fois (JSON du questionnaire versionné), évaluées par le même moteur sur le mobile (Dart) et sur le serveur (PHP). Le serveur rejoue tous les contrôles à chaque soumission.

| Code | Règle | Gravité |
|---|---|---|
| R-REQ | Obligatoire / obligatoire conditionnel non renseigné | Bloquant |
| R-NEG | Valeur numérique négative ou non entière | Bloquant |
| R-FMT-01 | Code structure ≠ 10 chiffres | Bloquant |
| R-FMT-02 | Téléphone ≠ 8 chiffres | Bloquant |
| R-DATE-01 | Date dans le futur | Bloquant |
| R-DATE-02 | Ouverture antérieure à l'autorisation | Avertissement |
| R-DATE-03 | Année de certification hors [1950, année courante] | Bloquant |
| R-SKIP | Réponse à une question masquée | Valeur effacée automatiquement et tracée |
| R-COH-01 | 2.1 = Σ Section IV (durée × secteur × sexe) | Garanti par calcul (D-01) |
| R-COH-02 | Totaux (2.1, 2.2, 2.19, 2.20, totaux secteur IV) = somme des composantes | Garanti par calcul |
| R-COH-03 | « Dont » handicapés/réfugiés/retournés/déplacés ≤ Total | Bloquant |
| R-COH-04 | 2.19 H/F = nombre de formateurs H/F en Section III | Garanti par calcul (D-08) |
| R-COH-05 | Réfugiés + retournés + déplacés ≤ Total | Avertissement |
| R-COH-06 | Chaque catégorie de 2.11.2 ≤ Q2_11_1 | Bloquant |
| R-COH-07 | 2.2 : placés ≤ inscrits et outillés ≤ inscrits (par durée × sexe) | Bloquant |
| R-COH-08 | 2.2 : placés + outillés > inscrits | Avertissement |
| R-COH-09 | Ligne métier dont tous les effectifs sont à 0 | Avertissement |
| R-COH-10 | Durée ≤ 3 mois avec des effectifs en Promotion II ou III | Avertissement |
| R-COH-11 | Aucun apprenti dans aucun secteur | Bloquant sauf observation renseignée |
| R-COH-12 | Apprentis > 0 et aucun formateur | Avertissement |
| R-COH-13 | Ratio apprentis / formateur > 60 (paramétrable) | Avertissement |
| R-COH-14 | 2.20 total > total inscrits 2.2 | Avertissement |
| R-COH-15 | Q1_13 renseignée pour une structure autre que plateforme | Effacée (R-SKIP) |
| R-GPS-01 | Point GPS éloigné de la commune déclarée (> seuil paramétrable, 15 km du centroïde par défaut, ou hors polygone si disponible) | Avertissement non bloquant |
| R-GPS-02 | Précision GPS > 50 m | Avertissement |

Les égalités strictes ne sont imposées que là où le questionnaire les implique (totaux, « dont »). Toute autre relation donne un avertissement que l'agent peut justifier.

---

## 6. Adaptations documentées

| Réf. | Adaptation | Justification |
|---|---|---|
| D-01 | 2.1 calculée depuis la Section IV (lecture seule ; affichée en Section II et en fin de Section IV) | supprime une double saisie source d'incohérences ; variable conservée telle quelle dans les exports |
| D-02 | Numérotation 2.14.2 → 2.13.2 | erreur matérielle |
| D-03 | Tableaux de 5 lignes → lignes dynamiques | exigence du cahier des charges |
| D-04 | Options « Autre » accompagnées d'un champ « préciser » | prévu par les pointillés du papier |
| D-05 | Longitude/latitude capturées par GPS (précision, horodatage, état) ; saisie manuelle de secours tracée | fiabilité |
| D-06 | Signature/cachet → attestation + photo facultative de la fiche papier signée | une signature tactile n'a pas de valeur juridique ; la photo garde une preuve |
| D-07 | Code, nom et localisation pré-remplis depuis le référentiel et verrouillés ; toute correction = demande de mise à jour soumise au superviseur | évite les doublons et les divergences de référentiel |
| D-08 | 2.19 calculée depuis la Section III | même logique que D-01 |
| D-09 | Enquêteur et superviseur (Section V) remplis automatiquement | traçabilité fiable, pas de ressaisie |
| D-10 | Totaux M + F calculés partout, jamais saisis | supprime une source d'erreurs |

---

## 7. Différences avec la version 2024 (retirée)

| Domaine | Version 2024 | Version 2025 (référence) |
|---|---|---|
| Tutelle | MEN/A/EP/PLN — DSPTICE | MEFTP — DSD |
| Ordre Section I | dates puis document administratif | document administratif (1.3) puis dates (1.4, 1.5) |
| Localisation pédagogique (DREN, IREFTP, IEFTP) | présente (1.7) | **supprimée** |
| Statut | public / privé / semi-public | public / privé |
| Types de structure | CMCAN, SNP, SIFA, CFA, SAA, Atelier, Foyer/Groupement, Coopérative, Cabinet, Garage, Autre | SFMA (SIFA), CFA, Atelier, SAA, **Foyer** et **Groupement séparés**, Garage, Coopérative, Cabinet, **Plateforme d'orientation**, Autre |
| Accompagnement 1.13 | tous | **plateformes uniquement**, option « Placement » ajoutée |
| Paiement | cash / chèque / en ligne | + **en nature** |
| 2.2 | par secteur | par durée × (inscrits, dont placés, dont outillés) |
| Cantine, cafétéria | présentes | **supprimées** |
| Nombre de formateurs | 3.1 (total) | 2.19 par sexe |
| Apprentis FAFPA | — | **2.20 nouvelle** |
| Section V | répondant, chef, Directeur départemental | répondant, chef, **enquêteur, superviseur** |
| Règles de saut | décalées de +3 (fausses) | correctes |

Conséquences pour la plateforme :
1. **Le versionnement du questionnaire est indispensable dès la v1** : deux versions officielles existent déjà en deux ans.
2. Les indicateurs du prompt maître fondés sur des questions supprimées (cantines, semi-public) ne sont pas calculables avec la version 2025 : les tableaux de bord s'appuient sur les variables de la version applicable à chaque campagne.
3. La comparaison pluriannuelle passe par une **table de correspondance des variables entre versions** (ex. Foyer/Groupement 2024 → e/f 2025), avec indicateurs marqués « non comparables » lorsque la correspondance est impossible.
