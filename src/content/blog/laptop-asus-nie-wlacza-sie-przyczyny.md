---
title: "Laptop ASUS nie włącza się: przyczyny i kolejność sprawdzeń"
description: "ASUS nie reaguje na przycisk zasilania? Sprawdź zasilacz, gniazdo i diody, zrób reset kontrolera i podłącz monitor. Kiedy winna jest płyta główna."
pubDate: 2026-11-14
category: "Naprawa"
service: "naprawa-laptopa-ktory-sie-nie-wlacza"
faq:
  - question: "Czy twardy reset kasuje dane?"
    answer: "Nie. Przytrzymanie przycisku odcina zasilanie i rozładowuje kondensatory, ale nie dotyka dysku ani plików. Dane zostają na miejscu, pod warunkiem że nie wyjmujesz baterii ani nie rozkręcasz obudowy."
  - question: "ASUS nie reaguje na ładowarkę. Czy to zawsze płyta główna?"
    answer: "Nie. Najczęściej winne jest gniazdo zasilania, kabel albo sam zasilacz. Płyta główna to zwykle ostatnia możliwość, którą potwierdzamy pomiarami. Bez pomiarów wymiana płyty jest zgadywaniem."
  - question: "Co zrobić, jeśli dioda miga?"
    answer: "Policz, ile razy dioda mignie w jednej serii i ile razy seria się powtarza. Zapisz to i podaj w serwisie. Kody migania różnią się między modelami, więc dwa identyczne mignięcia mogą oznaczać co innego."
draft: true
---

Naciskasz przycisk zasilania w ASUS-ie i nic się nie dzieje. Żadnej diody, żadnego dźwięku wentylatora, tylko cisza. Zanim zawieziesz laptopa do serwisu we Wrocławiu, warto poświęcić kilka minut na sprawdzenia, które odsieją najprostsze przyczyny. Część takich przypadków kończy się na zasilaczu, kablu albo zawieszonym kontrolerze zasilania, a nie na płycie głównej. Poniżej kolejność działań i objawy, które trzeba umieć rozpoznać.

## Zasilacz, kabel i gniazdo

Podłącz zasilacz bezpośrednio do gniazdka w ścianie, z pominięciem listwy z wyłącznikiem, i sprawdź, czy wtyczka wchodzi do laptopa do końca. Większość zasilaczy ASUS ma diodę, która świeci po podłączeniu do prądu. Jeśli nie świeci, usterki szukaj po stronie zasilacza, kabla albo gniazdka sieciowego, a nie laptopa. Obejrzyj kabel przy samej wtyczce, bo załamania i przetarcia dają dokładnie takie objawy jak awaria komputera.

ASUS-y dzielą się tu na dwie grupy. Starsze i gamingowe modele TUF oraz ROG mają okrągłą wtyczkę i wymagają zasilacza o konkretnym napięciu i polaryzacji - nie podłączaj zasilacza od innego modelu bez pewności co do parametrów. Nowsze ZenBooki i VivoBooki ładują się przez USB-C Power Delivery. W tej grupie sprawdź inny kabel USB-C i inną ładowarkę zgodną z PD, bo kabel od telefonu potrafi ładować słuchawki, a nie przekazać wystarczającej mocy do laptopa. Zbyt słaba ładowarka może nie uruchomić komputera nawet wtedy, gdy dioda na obudowie świeci.

Osobno obejrzyj gniazdo. Wtyczka, która siedzi luźno, wypada albo ładuje tylko pod kątem, to usterka mechaniczna. Nie wkładaj do gniazda śrubokręta i nie naginaj blaszek, bo łatwo zewrzeć piny.

## Reset kontrolera zasilania

Odłącz zasilacz i przytrzymaj przycisk zasilania przez około 30-40 sekund. Ten zabieg rozładowuje kondensatory na płycie i resetuje kontroler zasilania, który po nagłym odcięciu prądu albo zawieszeniu zostaje w błędnym stanie. W wielu przypadkach laptop, który wcześniej nie reagował, po takim resecie startuje normalnie.

W części modeli ASUS jest też mały otwór resetu na spodzie obudowy. Reset robi się przy odłączonym zasilaczu, cienkim spinaczem, jednym krótkim wciśnięciem. Baterii wbudowanej nie próbuj wyjmować samodzielnie, bo w większości nowych laptopów wymaga to rozkręcenia obudowy i odłączenia taśmy. Jeden lub dwa podejścia wystarczą, żeby ocenić reakcję - nie powtarzaj resetu kilkanaście razy pod rząd.

Zanim uznasz, że laptop nie startuje, odłącz od niego wszystko: pendrive, kartę pamięci, mysz, hub USB i stację dokującą. Wadliwy nośnik albo hub potrafi zatrzymać start na etapie wykrywania urządzeń. Zostaw tylko zasilacz.

## Co mówią diody

Świecenie diody ładowania bez przerwy oznacza zwykle, że laptop dostaje zasilanie i ładuje baterię, ale nie potrafi wystartować. Brak świecenia przy sprawnym zasilaczu zawęża pole do gniazda albo sekcji zasilania na płycie. Migające diody to najczęściej kod diagnostyczny producenta - policz mignięcia w serii i liczbę powtórzeń, a potem podaj je w serwisie, bo skraca to diagnostykę.

Kody nie są uniwersalne. To samo mignięcie w dwóch różnych modelach ASUS-a może oznaczać błąd pamięci albo problem z zasilaniem. Dlatego nie warto szukać w internecie jednej tabeli dla wszystkich laptopów - liczy się model i powtarzalność objawu.

## Monitor zewnętrzny i BIOS

Podłącz laptopa kablem HDMI do monitora albo telewizora i włącz oba urządzenia. Jeśli na zewnętrznym ekranie pojawi się obraz, płyta główna i system pracują, a problem dotyczy matrycy, taśmy albo podświetlenia. Wtedy nie ma sensu szukać usterki zasilania, tylko obejrzeć ekran i jego połączenie z płytą.

Jeśli laptop startuje, ale nie widzisz obrazu, spróbuj wejść do BIOS-u. W ASUS-ie klawisz F2 wciskany zaraz po włączeniu otwiera ustawienia, a Esc - menu rozruchu. Jeżeli BIOS się wyświetla, elektronika żyje i problem leży w systemie albo dysku. Jeśli BIOS nie wstaje również na zewnętrznym monitorze, komputer zatrzymuje się na inicjalizacji sprzętu.

## Kiedy podejrzewać płytę główną

Płyta główna jest realnym podejrzanym, gdy zasilanie dochodzi, ładowanie działa, wentylator startuje i po chwili gaśnie, a komputer nie wyświetla obrazu ani na matrycy, ani na monitorze. Podobnie przy powtarzających się restartach bez obrazu oraz po zalaniu lub zwarciu. W takich przypadkach potrzebne są pomiary napięć i kontrola sekcji zasilania, a nie wymiana kolejnych części. Naprawą takich uszkodzeń zajmujemy się w osobnym dziale: [naprawa płyt głównych](/naprawa-plyt-glownych/).

Bywa też inaczej. Winny jest przycisk zasilania, taśma klawiatury, pamięć RAM albo kontroler. W modelach z wyjmowaną pamięcią wadliwa kość potrafi zablokować start, ale przekładanie modułów bez wiedzy o rozmieszczeniu gniazd kończy się uszkodzeniem zatrzasków. Zostaw to serwisowi, który sprawdzi pamięć po pomiarach.

## Kiedy przyjechać do serwisu we Wrocławiu

Jeśli po sprawdzeniu zasilacza, resecie i teście z monitorem ASUS nadal nie startuje, dalsze próby w domu niewiele dadzą. W Studio-Komp przy Kotlarskiej 14 diagnostyka jest bezpłatna, jeśli zdecydujesz się na naprawę - jeśli nie, jest płatna, a o koszcie informujemy przed jej rozpoczęciem. Pracujemy od poniedziałku do piątku w godzinach 7:00-15:00, bez umawiania. Zadzwoń pod 500 613 500 i opisz objawy, a podpowiemy, czy warto przywozić sprzęt od razu.

Przygotuj krótką notatkę: kiedy problem się pojawił, czy poprzedzało go zdarzenie, jak zachowują się diody i czy laptop wydaje jakiekolwiek dźwięki. Całą usługę opisuje strona [naprawa laptopa, który się nie włącza](/naprawa-laptopa-ktory-sie-nie-wlacza/), a szczegóły wizyty - [diagnostyka laptopa](/diagnostyka-laptopa-wroclaw/). Ekspres z dopłatą 150 zł skraca oczekiwanie do tego samego dnia, jeśli część jest dostępna. Przynieś zasilacz i podaj hasło do systemu, jeśli laptop je ma.
