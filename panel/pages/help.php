<?php
/**
 * Pomoc: krótka instrukcja dla właściciela + lista kontrolna.
 */

layout_head('Pomoc panelu', '');
?>

<div class="grid grid--2">
  <section class="card">
    <header class="card__head"><h2>Najczęstsze zadania</h2></header>
    <div class="card__body">
      <ol class="list-plain" style="counter-reset:step">
        <li><strong>Dodaj artykuł:</strong> Poradnik → „Dodaj nowy" → wypełnij tytuł, opis, treść → zapisz. Jeśli chcesz najpierw sprawdzić, zostaw „wersja robocza".</li>
        <li><strong>Dodaj realizację:</strong> Realizacje → „Dodaj realizację" → kategoria, opis problemu/diagnozy/naprawy/wyniku, zdjęcia. Zapis automatycznie eksportuje dane do strony.</li>
        <li><strong>Wgraj zdjęcie:</strong> Media → „Dodaj plik" → uzupełnij ALT (opis dla Google i osób niewidzących).</li>
        <li><strong>Zmień cenę:</strong> Ustawienia → Cennik → edytuj pozycję → zapisz.</li>
        <li><strong>Zmień telefon/adres:</strong> Ustawienia → Kontakt. Zmiana działa w całym serwisie.</li>
        <li><strong>Przekierowanie:</strong> Przekierowania → dodaj stary i nowy adres (301).</li>
        <li><strong>Publikacja:</strong> przycisk „Publikuj" uruchamia build na komputerze i podmienia wersję na serwerze.</li>
      </ol>
    </div>
  </section>

  <section class="card">
    <header class="card__head"><h2>Zasady, które chronią stronę</h2></header>
    <div class="card__body">
      <ul class="list-plain small">
        <li>✔ Nie usuwaj podstron, które mają ruch - zamiast tego ustaw przekierowanie 301.</li>
        <li>✔ Nie kopiuj tekstów z innych stron; Google karze za duplikaty.</li>
        <li>✔ Nie obiecuj w treściach rzeczy, których serwis nie robi (np. „autoryzowany serwis").</li>
        <li>✔ Nie podawaj cen, których nie znasz - zostaw „… zł" lub „wycena po diagnozie".</li>
        <li>✔ Zdjęcia: prawdziwe, własne, z opisem ALT; nie wgrywaj plików z internetu.</li>
        <li>✔ Publikuj pojedyncze, sprawdzone zmiany - nie wszystko naraz.</li>
        <li>✔ Wersje robocze (szkice) nie są publikowane; podgląd pokazuje je tylko Tobie.</li>
        <li>✔ Panel zapisuje historię zmian i pozwala przywrócić poprzednią wersję.</li>
      </ul>
    </div>
  </section>

  <section class="card">
    <header class="card__head"><h2>Bezpieczeństwo</h2></header>
    <div class="card__body">
      <ul class="list-plain small">
        <li><strong>2FA:</strong> włącz w „Mój profil" - kod z aplikacji przy logowaniu.</li>
        <li><strong>Hasła:</strong> minimum 12 znaków, unikalne dla panelu.</li>
        <li><strong>Wylogowanie:</strong> automatyczne po 30 min bezczynności.</li>
        <li><strong>Dostęp:</strong> panel jest wyłączony z indeksowania (noindex) i wymaga logowania.</li>
        <li><strong>Sekrety:</strong> klucze API trzymamy w konfiguracji serwera, nie w repozytorium.</li>
      </ul>
    </div>
  </section>

  <section class="card">
    <header class="card__head"><h2>Wersje robocze i podgląd</h2></header>
    <div class="card__body">
      <p class="small">
        Każdy artykuł, usługa, marka i podstrona ma przełącznik „wersja robocza".
        Szkic nie pojawia się na produkcji, ale jest widoczny w Podglądzie.
        Po zatwierdzeniu treści wyłącz tryb roboczy i opublikuj stronę.
      </p>
      <p class="small muted" style="margin-top:10px">
        Nowe treści marek (10 podstron i 27 artykułów) zostały przygotowane jako
        materiał do zatwierdzenia - sprawdź je i zdecyduj, które włączasz.
      </p>
    </div>
  </section>

  <section class="card">
    <header class="card__head"><h2>Diagnostyka</h2></header>
    <div class="card__body">
      <ul class="list-plain small">
        <li><strong>Strona nie ma nowych treści?</strong> Sprawdź Publikacja → status i log builda.</li>
        <li><strong>Nie widzisz zmian na stronie?</strong> Odśwież z Ctrl+F5 (cache przeglądarki).</li>
        <li><strong>Błąd Google API opinii?</strong> Zostają ostatnie dane; sprawdź klucz i limity w Google Cloud.</li>
        <li><strong>Zapomniałeś hasła?</strong> Administrator może zresetować w Użytkownicy.</li>
        <li><strong>Awaria?</strong> Każda wersja strony jest zachowana - Publikacja → „Przywróć" wraca do poprzedniej.</li>
      </ul>
    </div>
  </section>
</div>

<?php layout_foot(); ?>
