---
title: "MacBook nie ładuje baterii: co sprawdzić przed serwisem"
description: "MacBook nie ładuje się? Sprawdź ładowarkę, kabel, port USB-C i stan baterii w macOS, wykonaj reset SMC na Intelu. Kiedy winna jest płyta, a kiedy sama bateria."
pubDate: 2026-11-20
category: "Naprawa"
service: "naprawa-laptopow-wroclaw"
faq:
  - question: "Czy MacBook ładuje się ładowarką od iPhone'a?"
    answer: "Możesz spróbować, ale ładowarki do telefonu mają zbyt małą moc, żeby utrzymać laptopa w pracy pod obciążeniem — komputer może pokazywać, że nie ładuje. Do codziennego użytku potrzebna jest ładowarka o mocy przewidzianej dla modelu. Taka próba pomaga tylko ustalić, czy port i kabel w ogóle reagują."
  - question: "Czy reset SMC pomoże, jeśli MacBook nie ładuje?"
    answer: "Na Intelu może pomóc, jeśli kontroler zasilania się zawiesił, ale nie naprawi uszkodzonego portu, kabla ani baterii. Na MacBookach z Apple silicon resetu SMC nie ma — zamiast tego wymusza się restart przyciskiem zasilania."
  - question: "Dlaczego MacBook pokazuje 80% i nie ładuje dalej?"
    answer: "To może być optymalizacja ładowania, która wstrzymuje ładowanie, gdy komputer jest podłączony do prądu przez dłuższy czas. To funkcja ochrony baterii, nie usterka. Jeśli jednak procent stoi przy 80% na krótkiej sesji i kondycja baterii jest zła, warto sprawdzić ogniwo."
draft: true
---

Podłączasz ładowarkę, a MacBook dalej pokazuje, że nie ładuje. Albo procent stoi w miejscu, albo komputer w ogóle nie reaguje na zasilanie. Zanim uznasz, że bateria jest do wymiany, warto przejść kilka prostych sprawdzeń, które odsieją kabel, port i ustawienia systemu. Poniżej kolejność działań — od najprostszych do tych, które wymagają pomiarów w serwisie.

## Ładowarka, kabel i port

Zacznij od ładowarki. Sprawdź, czy działa z innym urządzeniem albo czy dioda (jeśli jest) świeci. Starsze MacBooki używają złącza MagSafe, nowsze — USB-C, a modele Pro 14 i 16 cali z serii M — MagSafe 3. W każdym przypadku sprawdź, czy ładowarka ma moc przewidzianą dla tego modelu. Ładowarka o niższej mocy potrafi utrzymać komputer przy życiu, ale nie naładuje baterii, a przy dużym obciążeniu komputer pokaże, że nie ładuje.

Podmień kabel na taki, który jest przystosowany do ładowania, a nie tylko do przesyłania danych. Kabel od telefonu bywa za słaby, mimo że pasuje. Jeśli MacBook ma kilka portów USB-C, spróbuj innego — uszkodzenie jednego portu nie wyklucza pozostałych. Obejrzyj wtyczkę i gniazdo: pył, zabrudzenia i ciemny nalot na stykach potrafią przerwać ładowanie. Port przedmuchaj delikatnie sprężonym powietrzem, ale nie wkładaj do niego metalowych przedmiotów.

## Co mówi macOS

Zajrzyj do Ustawień systemowych, do sekcji Bateria. Znajdziesz tam kondycję baterii i informację o optymalizacji ładowania. Komunikat o konieczności serwisu baterii oznacza, że ogniwo wymaga wymiany. Liczbę cykli sprawdzisz w Informacjach o systemie, w sekcji dotyczącej zasilania.

Sprawdź, czy nie jest włączona optymalizacja ładowania. macOS potrafi wstrzymać ładowanie na około 80 procentach, gdy komputer jest długo podłączony do prądu, żeby chronić ogniwo. To normalne zachowanie, a nie usterka. Podobnie działa zarządzanie baterią na starszych Intelach. Jeśli jednak procent stoi przy 80% na krótkiej sesji, a kondycja ogniwa jest zła, podejrzenie pada na baterię.

Osobno zwróć uwagę na komunikat „podłączono do zasilania, ale nie ładuje”. Jeśli system widzi zasilanie, a mimo to bateria nie rośnie, problem może leżeć w baterii albo w układzie ładowania. Jeśli system w ogóle nie widzi ładowarki, szukaj usterki w kablu, porcie albo kontrolerze na płycie.

## Reset SMC i NVRAM

Na MacBookach z procesorami Intela reset SMC bywa pierwszym krokiem, gdy komputer nie ładuje albo nie startuje prawidłowo. W modelach z niewyjmowalną baterią robi się go kombinacją Shift, Control, Option i przycisku zasilania, przytrzymaną przez około 10 sekund. W starszych modelach z wyjmowalną baterią wystarczyło ją wyjąć i włożyć. NVRAM resetuje się kombinacją Option, Command, P i R przytrzymaną podczas startu.

Na MacBookach z Apple silicon nie ma resetu SMC ani ręcznego resetu NVRAM w starym znaczeniu — te funkcje przejął system. Zamiast tego, gdy komputer nie reaguje, wymusza się wyłączenie przytrzymaniem przycisku zasilania przez około 10 sekund. To nie naprawi uszkodzonego portu, ale potrafi wyjść z zawieszenia kontrolera.

Warto wiedzieć, że reset SMC nie jest lekiem na wszystko. Jeśli po resecie ładowanie wraca, prawdopodobnie kontroler się zawiesił i problem może się powtórzyć. Jeśli nic się nie zmienia, potrzebne są pomiary.

## Bateria czy układ ładowania

Zużyta bateria ma typowe objawy: skrócony czas pracy, nagłe wyłączanie przy kilkudziesięciu procentach, ładowanie do pełna w kilka minut i szybki spadek po odłączeniu zasilacza. Innym sygnałem jest wybrzuszona obudowa albo trackpad, który przestał klikać, bo ogniwo napiera na niego od środka. Spuchniętej baterii nie należy dalej używać.

Awaria układu ładowania wygląda inaczej: komputer pracuje na zasilaczu, ale system nie widzi ładowania, a port bywa luźny albo martwy. Odpowiadają za to kontrolery USB-C, tranzystory MOSFET i bezpieczniki na płycie. Padają po zwarciu, przepięciu albo zalaniu. Taka usterka wymaga pomiarów napięć, a nie wymiany baterii na wyczucie.

## Wymiana baterii i naprawa płyty

Bateria w MacBooku jest klejona do obudowy, a jej ogniwa są cienkie i delikatne. Podważanie jej śrubokrętem grozi przebiciem ogniwa i pożarem, a przy okazji uszkodzeniem trackpada i klawiatury. W serwisie zdejmujemy klej, odłączamy kontroler baterii i montujemy nowy pakiet, a potem sprawdzamy ładowanie i kondycję ogniwa.

Jeśli winna jest płyta, zakres zależy od modelu. W starszych Intelach da się naprawić sekcję zasilania i kontroler ładowania. W Apple silicon płyta jest gęsto zabudowana, więc część usterek kończy się wymianą całej płyty albo brakiem opłacalnej naprawy. Mówimy to wprost po diagnostyce, zamiast obiecywać naprawę, której nie da się wykonać.

## Kiedy przyjechać do serwisu we Wrocławiu

Jeśli po podmianie ładowarki i kabla oraz sprawdzeniu portów MacBook nadal nie ładuje, dalsze próby w domu niewiele dadzą. Przynieś laptop, ładowarkę i kabel na Kotlarską 14 we Wrocławiu. Pracujemy od poniedziałku do piątku w godzinach 7:00–15:00, bez umawiania. Telefon: 500 613 500.

Diagnostyka jest bezpłatna, jeśli zlecasz naprawę — inaczej jest płatna, a o koszcie informujemy przed jej rozpoczęciem. Zakres napraw opisuje strona [naprawy laptopów](/naprawa-laptopow-wroclaw/), a problemy z samym gniazdem — [naprawa gniazda zasilania](/naprawa-gniazda-zasilania/). Ekspres z dopłatą 150 zł pozwala odebrać sprzęt tego samego dnia, jeśli część jest dostępna. Podaj hasło do systemu, jeśli komputer je ma — bez tego nie sprawdzimy stanu baterii w systemie.
