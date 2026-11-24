# Brief treściowy — Studio-Komp (przeczytaj w całości przed pisaniem)

## 1. Firma — fakty, których wolno używać

- Studio-Komp, serwis laptopów i komputerów, Kotlarska 14, 50-150 Wrocław (Stare Miasto).
- Telefon: 500 613 500. Godziny: poniedziałek–piątek 7:00–15:00. Sobota i niedziela zamknięte.
- Działa od 2013 roku.
- Ponad 550 prawdziwych opinii Google (ocena ok. 4,9/5).
- 99% napraw wykonują z dnia na dzień.
- Usługa ekspresowa: naprawa tego samego dnia, dopłata 150 zł. Nie zawsze możliwa — zależy od usterki i dostępności części.
- Diagnostyka bezpłatna, jeśli klient zdecyduje się na naprawę. Jeśli nie — płatna (kwota podawana przed diagnostyką).
- Bez umawiania — klient może przyjść w godzinach pracy.
- Naprawiają wszystkie popularne marki (Lenovo, Dell, HP, Asus, Acer, MSI, Samsung, Apple, Toshiba, Microsoft i inne), sprzęt prywatny, firmowy, gamingowy i premium.
- Specjalizacja: naprawy po zalaniu i po zwarciu, naprawy płyt głównych.

## 2. Cennik — JEDYNE dozwolone kwoty

- Czyszczenie i konserwacja laptopa: od 200 zł (na stronie głównej 200–250 zł)
- Wymiana gniazda zasilania: 350 zł
- Naprawa laptopa po zalaniu: 450–800 zł
- Naprawa płyty głównej po zwarciu: 600–800 zł
- Instalacja systemu Windows/macOS/Linux: 250–500 zł
- Odzyskiwanie danych: 300–800 zł
- Ekspres: +150 zł

Dla pozostałych usług NIE podawaj ceny. Użyj sformułowania „cena po diagnozie” / „wycena przed naprawą”. Nie wymyślaj widełek, gwarancji, „od 50 zł” itp.

## 3. Zasady bezwzględne

- NIE wymyślaj faktów: żadnych certyfikatów, nagród, liczby naprawionych urządzeń, lat gwarancji, nazwisk pracowników, marek sprzętu, których nie ma na liście, konkretnych czasów typu „45 minut”, jeśli nie wynikają z briefu.
- Nie obiecuj rzeczy, których firma nie zadeklarowała. Możesz opisywać standardowy przebieg naprawy i to, co dzieje się w serwisie.
- Nie pisz o sobie „najlepszy serwis w Polsce” itp. Zamiast tego konkret: „od 2013 roku”, „550+ opinii”, „99% napraw z dnia na dzień”.
- Żadnego keyword stuffingu. Fraza „Wrocław” pojawia się naturalnie: w H1, pierwszym akapicie, jednym z H2, w treści kilka razy — nie w każdym zdaniu.
- Każda strona musi być unikalna. Nie kopiuj akapitów między plikami. Nie zaczynaj dwóch tekstów tym samym zdaniem.
- Ton: profesjonalny, ludzki, bezpośredni. Pisz „Ty/Twój” (nie „Państwo”). Krótkie zdania, konkrety, zero korporacyjnego żargonu, zero wykrzykników.
- Zakazane frazy i konstrukcje: „w dzisiejszych czasach”, „nie da się ukryć”, „kompleksowe rozwiązania”, „w trosce o”, „jak wiadomo”, „podsumowując”, „dlatego warto”, „niezbędnym elementem jest”, „wychodząc naprzeciw”, „kluczem do sukcesu”. Nie zaczynaj akapitów od „Warto zauważyć”.
- Nie używaj emoji. Nie używaj em-dashów w nadmiarze (max 1–2 na tekst).
- Polszczyzna poprawna, z diakrytykami. Liczby: „99%”, „550+”, „150 zł” (spacja przed zł). Myślnik w zakresach: „450–800 zł”.

## 4. Format plików usług: `src/content/uslugi/<slug>.md`

Frontmatter (dokładnie te pola, YAML):

```yaml
---
title: "Meta title bez nazwy firmy, 40–60 znaków, zawiera frazę + Wrocław"
navLabel: "Krótka nazwa do menu (2–4 słowa)"
description: "Meta description 140–160 znaków, konkret, zachęta, bez clickbaitu"
h1: "Nagłówek H1 strony — inny niż title, może być dłuższy"
lead: "1–2 zdania pod H1. Problem klienta + obietnica."
teaser: "Jedno zdanie do listy usług (max 110 znaków)."
group: "Konserwacja" | "Naprawy" | "Elektronika" | "System i dane"
order: <liczba>
featured: true | false
priceFrom: "od 200 zł"        # tylko gdy cena znana
priceNote: "Cena po diagnozie..."  # gdy cena zależy od modelu
duration: "zwykle z dnia na dzień"
includes:
  - "punkt 1 (co zawiera usługa)"
  - "punkt 2"
  - "punkt 3"
  - "punkt 4"
problems:
  - "objaw/problem klienta 1"
  - "objaw 2"
  - "objaw 3"
faq:
  - question: "Pytanie?"
    answer: "Odpowiedź 2–4 zdania, konkretna."
  - question: "Drugie pytanie?"
    answer: "Odpowiedź."
  - question: "Trzecie pytanie?"
    answer: "Odpowiedź."
related:
  - "slug-innej-uslugi"
updated: 2026-11-24
---
```

Body (Markdown, 450–650 słów, BEZ H1 — H1 jest w frontmatterze):

- Akapit otwierający (2–4 zdania): rozpoznanie sytuacji klienta, bez powtarzania lead.
- `## ` sekcje: 3–5. Pierwsza zwykle o objawach/przyczynach, ostatnia o tym, jak wygląda wizyta w serwisie lub kiedy przyjechać.
- Maksymalnie 2 listy punktowane w całym tekście.
- 2–3 linki wewnętrzne wplecione w zdania, format: `[kotwica](/slug-innej-uslugi/)` lub `[cennik](/cennik/)`, `[kontakt](/kontakt/)`. Linki z ukośnikiem na końcu, bez domeny.
- Akapit zamykający: konkretne CTA (zadzwoń / przyjdź bez umawiania), bez nachalności.
- Nie umieszczaj tabel, obrazków, HTML.

## 5. Format plików bloga: `src/content/blog/<slug>.md`

```yaml
---
title: "Tytuł artykułu — konkretny, 45–65 znaków, bez clickbaitu"
description: "Meta description 140–160 znaków"
pubDate: 2026-10-12
category: "Naprawa" | "Konserwacja" | "Wydajność" | "Dane i system" | "Zakup i modernizacja" | "Poradnik"
service: "slug-uslugi"   # główna usługa, do której prowadzi artykuł
faq:
  - question: "Pytanie?"
    answer: "Odpowiedź."
  - question: "Pytanie?"
    answer: "Odpowiedź."
---
```

Body: 900–1300 słów, BEZ H1 (H1 z title). Struktura:
- Akapit wprowadzający: konkretna sytuacja, w której czytelnik szuka pomocy. 3–5 zdań.
- `## ` sekcje merytoryczne (4–7), w środku czasem `### `.
- Porady krok po kroku, kiedy można spróbować samemu, a kiedy od razu do serwisu.
- Jedna sekcja z linkiem do usługi: np. `## Kiedy przyjechać do serwisu` + link `[naprawa po zalaniu](/naprawa-laptopa-po-zalaniu/)`.
- Zakończenie bez „podsumowania” — ostatni akapit to konkretna rada lub CTA.
- Maksymalnie 3 listy punktowane.
- Linki wewnętrzne: 2–4 na artykuł, tylko do istniejących ścieżek.
- Pisz jak doświadczony technik, który tłumaczy koledze — nie jak copywriter SEO. Unikaj ogólników; podawaj objawy, nazwy podzespołów, kolejność działań.

## 6. Mapa adresów (używaj dokładnie tych slugów w linkach)

Usługi:
- /serwis-laptopow-wroclaw/
- /naprawa-laptopow-wroclaw/
- /czyszczenie-laptopa-wroclaw/
- /przyspieszanie-laptopa-wroclaw/
- /wymiana-matrycy-wroclaw/
- /naprawa-laptopa-po-zalaniu/
- /naprawa-plyt-glownych/
- /naprawa-laptopa-ktory-sie-nie-wlacza/
- /naprawa-gniazda-zasilania/
- /naprawa-zawiasow/
- /naprawa-komputerow-wroclaw/
- /wymiana-klawiatury-laptopa/
- /naprawa-portow-usb-hdmi/
- /odzyskiwanie-danych-wroclaw/
- /instalacja-windows-wroclaw/
- /diagnostyka-laptopa-wroclaw/

Inne: /cennik/, /kontakt/, /uslugi/, /opinie/, /realizacje/, /o-serwisie/, /poradnik/

## 7. Checklista przed oddaniem

1. YAML parsuje się (cudzysłowy wokół stringów zawierających `:`).
2. Brak H1 w body.
3. Długość zgodna z briefem.
4. Zero wymyślonych faktów i cen.
5. Linki tylko do istniejących slugów, z ukośnikiem na końcu.
6. Unikalny pierwszy akapit (nie taki sam jak w innym pliku).
7. Polskie znaki, poprawna interpunkcja, bez emoji i bez wykrzykników.
