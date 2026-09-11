# Schéma — Quote Items côté Frontend

> Document généré à partir du code réel : `frontEnd/src/Quotes.jsx`
> (fichier unique gérant les lignes de devis, côté React + Vite)

## 1. Vue d'ensemble (architecture)

```
┌───────────────────────────────────────────────────────────────────────────┐
│                          FRONTEND (React + Vite)                          │
│                                                                            │
│   frontEnd/.env                                                            │
│   └── VITE_API_URL = http://192.168.110.162:8000     (URL du backend)      │
│                                                                            │
│   ┌─────────────────── Quotes.jsx  (composant unique) ─────────────────┐   │
│   │   Un seul fichier gère TOUTES les lignes de devis                  │   │
│   │                                                                    │   │
│   │   helper `request(path, options)`  ──── le "câble" HTTP ──────┐   │   │
│   │      fetch(VITE_API_URL + path)                                │   │   │
│   │      headers: Authorization: Bearer <auth_token>               │   │   │
│   │              + Content-Type: application/json                  │   │   │
│   └────────────────────────────────────────────────────────────────┴───┘   │
└───────────────────────────────────────────────────────────────────────────┘
```

## 2. Les états gérés (`Quotes.jsx`, lignes 24–39)

```
┌─ States Quotes.jsx ───────────────────────────────────────────────┐
│                                                                   │
│  selectedQuote  → devis ouvert dans le panneau "Lignes"           │
│  quoteItems     → tableau des lignes chargées (fetch GET)         │
│  itemForm       → formulaire actif (une seule ligne éditée)      │
│       { product_id, description, quantity, unit_price, tax_rate }│
│  editingItem    → null = création | ligne = modification (PUT)    │
│                                                                   │
│  + flags : isItemsLoading, isItemSubmitting, message              │
└───────────────────────────────────────────────────────────────────┘
```
## 3. Les deux UI où les quote items apparaissent

```
┌─ ① FORMULAIRE "Nouveau/Modifier devis" (l.400-438) ────────────────┐
│   formData.items[]  (lignes embarquées AVANT création du devis)    │
│                                                                     │
│   [PRODUIT▾] [DESCRIPTION] [QTE] [PRIX UNITAIRE] [TVA%] [MONTANT] │
│   [+ Ajouter une ligne]  → addItem() / removeItem(index)          │
│   POST/PUT  /api/quotes  → syncItems() côté backend                │
└─────────────────────────────────────────────────────────────────────┘

┌─ ② PANNEAU "Lignes du devis" pour un devis EXISTANT (l.440-459) ──┐
│   Ouvert via bouton [Lignes] dans la liste des devis (l.463)        │
│   → openItemsManager(quote) → loadQuoteItems(quote.id)             │
│                                                                     │
│   ┌─ Formulaire ligne (l.442-457) ─┐   ┌─ Tableau lignes (l.458) ─┐│
│   │ PRODUIT▾  DESCRIPTION  QTE     │   │ DESCRIPTION | PRODUIT     ││
│   │ PRIX UNIT  TVA %  MONTANT      │   │ QTE | PRIX UNIT | TVA     ││
│   │ [Ajouter la ligne]             │   │ TOTAL | [Modifier][Suppr.]││
│   └────────────────────────────────┘   └───────────────────────────┘│
└─────────────────────────────────────────────────────────────────────┘
```

## 4. Flux des données (fonctions frontend ↔ API backend)

```
┌─ Actions utilisateur ─┐     ┌─ Fonctions Quotes.jsx ──┐    ┌─ API backend (QuoteItemController) ─┐
│                        │     │                          │    │                                    │
│ Ouvrir "Lignes"        │───►│ loadQuoteItems(quoteId) │──►│ GET    /api/quotes/{q}/items       │
│    (bouton liste l.463)│     │   l.288-299             │    │                                    │
│                        │     │                          │    │                                    │
│ Remplir formulaire +   │     │ handleItemFormChange    │    │                                    │
│ choisir un produit     │───►│   auto-remplissage       │    │  (pré-remplissage depuis la liste   │
│                        │     │   description/prix/tva  │    │   products déjà chargée, l.307-314) │
│                        │     │   l.301-319             │    │                                    │
│ [Ajouter la ligne]     │───►│ handleItemSubmit        │──►│ POST   /api/quotes/{q}/items       │
│                        │     │   l.338-366             │    │  → 201 {quoteItem}                │
│ [Modifier]             │───►│ startEditItem →         │──►│ PUT    /api/quotes/{q}/items/{i}   │
│                        │     │ handleItemSubmit (PUT)  │    │  → 200 {quoteItem}                │
│ [Supprimer]            │───►│ handleItemDelete        │──►│ DELETE /api/quotes/{q}/items/{i}   │
│                        │     │   l.368-376             │    │  → 200 {message}                  │
│ [Actualiser]           │───►│ loadQuoteItems(quoteId) │──►│ GET    (rechargement)              │
│                        │     │                          │    │                                    │
│ Après tout CRUD ──────►│ loadQuotes() ─────────────────────►│ GET /api/quotes (liste, totaux    │
│                        │   l.360 & 376 (raffraîchit         │    recalculés côté backend)       │
│                        │   les totaux du devis)             │                                    │
└────────────────────────┘     └──────────────────────────┘    └────────────────────────────────────┘
```