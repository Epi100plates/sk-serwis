---
title: "Laptop nie ładuje baterii - co sprawdzić krok po kroku"
description: "Bateria stoi na tym samym procencie, choć zasilacz jest podłączony? Sprawdź limit ładowania, zasilacz, gniazdo i stan baterii. Wyjaśniamy, kiedy potrzebne są pomiary w serwisie."
pubDate: 2026-11-27
updatedDate: 2026-11-27
category: "Naprawa"
service: "naprawa-gniazda-zasilania"
readingTime: 3
faq:
  - question: "Dlaczego laptop ładuje baterię tylko do 60 lub 80 procent?"
    answer: "To tryb ochrony baterii, dostępny w wielu laptopach. Producent ogranicza ładowanie, żeby ogniwo dłużej zachowało pojemność. To normalne zachowanie, a nie usterka - sprawdź ustawienia zasilania i aplikację producenta."
  - question: "Czy ładowarka USB-C o mniejszej mocy wystarczy?"
    answer: "Do pracy biurowej często tak, do ładowania w trakcie obciążenia nie zawsze. Laptop z USB-C potrzebuje ładowarki zgodnej z Power Delivery i o mocy, którą przewidział producent. Zbyt słaby zasilacz nie uzupełni baterii podczas pracy."
  - question: "Czy wymiana baterii rozwiąże problem?"
    answer: "Nie zawsze. Zużyta bateria rzadko blokuje ładowanie całkowicie. Jeśli laptop pracuje na zasilaczu, ale procent stoi, częściej winny jest układ ładowania na płycie albo limit ustawiony w systemie. Decyzję podejmujemy po pomiarach."
---

Laptop działa, ale bateria stoi na tym samym procencie. Po odłączeniu kabla komputer gaśnie po kilku minutach albo procent spada mimo podłączonego zasilacza. To inny objaw niż "laptop w ogóle się nie ładuje" i inna droga sprawdzenia. Zanim uznasz, że bateria jest do wymiany, przejdź przez kilka punktów w tej kolejności.

## Najpierw system: limit ładowania i tryb ochrony

Wiele laptopów ma funkcję, która zatrzymuje ładowanie na 60, 80 albo 100 procentach, żeby bateria dłużej zachowała pojemność. Nazywa się różnie: limit ładowania, tryb konserwacji, battery care. Włączona funkcja oznacza, że komputer nie ładuje dalej i to jest poprawne zachowanie, a nie usterka.

Zajrzyj do aplikacji producenta i do ustawień zasilania w systemie. Sprawdź też, czy laptop nie pracuje w trybie oszczędzania energii, który ogranicza ładowanie. Jeśli wcześniej działało normalnie, a teraz zatrzymuje się na stałym procencie, porównaj to z tym, co pokazuje narzędzie producenta.

W Windows możesz wpisać w wierszu poleceń `powercfg /batteryreport` i otworzyć wygenerowany raport. Zobaczysz w nim pojemność fabryczną, aktualną pojemność i historię ładowania. To pierwszy krok, żeby oddzielić zużycie baterii od problemu z ładowaniem.

## Zasilacz, kabel i port

Sprawdź, czy zasilacz świeci po podłączeniu do sieci i czy kabel nie jest złamany przy wtyczce. Podmień kabel albo ładowarkę, jeśli masz taką możliwość. W laptopach z USB-C wypróbuj inny port: czasem jeden z portów obsługuje ładowanie, a drugi nie.

Porównaj moc zasilacza z wymaganiami modelu. Zbyt słaby zamiennik albo ładowarka od telefonu nie uzupełni baterii w trakcie pracy, a laptop może pokazywać, że jest podłączony, mimo że energia ledwo wystarcza na bieżące zużycie. To częsty problem przy laptopach gamingowych i stacjach dokujących.

## Gniazdo i wtyczka

Charakterystyczny objaw uszkodzonego gniazda: ładowanie pojawia się tylko po ustawieniu wtyczki pod kątem albo po przytrzymaniu kabla. Przy każdym ruchu laptopa dioda gaśnie. To luz mechaniczny albo pęknięte luty, a nie problem z baterią.

Obejrzyj gniazdo pod światło. Ciemne lub nadtopione plastikowe elementy, ślady iskrzenia i luźna wtyczka znaczą, że styk pracuje nieprawidłowo. Nie wkładaj do gniazda śrubokręta i nie naginaj blaszek - łatwo zewrzeć piny. Uszkodzone gniazdo opisujemy na stronie [naprawy gniazda zasilania](/naprawa-gniazda-zasilania/).

## Bateria: zużycie czy awaria

Bateria po kilku latach traci pojemność i to normalne. Objawy zużycia to nagłe wyłączenia przy 20-30 procentach, bardzo szybkie ładowanie do pełna i krótki czas pracy bez zasilacza. W raporcie baterii zobaczysz, o ile spadła pojemność względem fabrycznej.

Inaczej wygląda awaria układu ładowania. Laptop pracuje z zasilacza, procent stoi, a system nie widzi ładowania. Przyczyną bywają kontroler ładowania, mosfety i bezpieczniki na płycie, które padają po zwarciu, przepięciu lub zalaniu. Wymiana baterii na wyczucie nic wtedy nie da.

Jedno trzeba sprawdzić od razu: czy bateria nie jest wybrzuszona. Jeśli obudowa się rozchodzi, a pod klawiaturą czuć wybrzuszenie, wyłącz laptopa, odłącz zasilacz i nie próbuj go dalej używać. To sytuacja, w której trzeba przyjechać do serwisu, a nie testować kolejne ładowarki.

## Kiedy jechać do serwisu

Przyjedź, jeśli dioda przy gnieździe nie świeci mimo sprawnego zasilacza, ładowanie działa tylko pod kątem, procent stoi przy znanym dobrym zasilaczu, bateria jest wybrzuszona albo czuć zapach spalenizny. Nie czekaj też, jeśli laptop był zalany - podłączanie go do prądu w takim stanie zwiększa szkody.

W serwisie mierzymy napięcie zasilacza i pobór prądu, sprawdzamy gniazdo, bezpieczniki i układ ładowania. Dzięki temu wiemy, czy problem dotyczy gniazda, baterii, zasilacza, czy sekcji zasilania na płycie. Standardowa wymiana gniazda kosztuje {{cena:wymiana-gniazda-zasilania}}, a diagnostyka bez naprawy {{cena:diagnostyka-bez-naprawy}}.

Krótszą listę przyczyn, także dla przypadku, gdy laptop nie ładuje się wcale, znajdziesz w artykule [laptop nie ładuje się - przyczyny](/poradnik/laptop-nie-laduje-sie-przyczyny/). Stawki innych prac są w [cenniku](/cennik/). Przyjdź na Kotlarską 14 we Wrocławiu w dni robocze między 7:00 a 15:00, bez umawiania, albo zadzwoń pod 500 613 500 i opisz objawy.
