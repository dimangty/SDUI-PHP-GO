# Task 1 — independent spec and code-quality review

Дата: 2026-10-27. База: `b9def93963ef08b1d19bedf957c0b3d3d003add7`.

## Вердикт

- **Spec: PASS для реализации Task 1.** Изменения соответствуют контракту
  `PLAN.md:49–63` и направлению `docs/images/frontend-sketch.svg` с явно
  согласованными отличиями: общий текст приветствия, сохранение альтернатив входа
  и явное подтверждение валидного PIN длиной 4–6 цифр.
- **Code quality: PASS. Critical: 0; High: 0; Medium: 0.** Подтверждённых
  дефектов этих уровней в проверенном Task 1 diff не найдено.
- Это разрешает дальнейшую интеграцию Task 1. Визуальное acceptance установленной
  сборки, клавиатуры и малых экранов остаётся отдельным финальным gate из
  `PLAN.md:103–114`; данное ревью не подтверждает pixel-match или полный UI-релиз.

## Область и метод

Прочитаны `PLAN.md`, `TASK1_REPORT.md`, SVG и исходный
`docs/backend-fixes/IOS_VISUAL_AUDIT.md`. Проверен фактический незакоммиченный diff
от указанной базы: shared theme/tokens, UIKit primitives, app bar/navigation,
`EpsAuth*`, PIN presentation, изменённые auth-тесты и новые `ShopTokens.kt`,
`EpsIcon.kt`, `EpsTopBarActionState.kt`, `SharedAuthControlsUiTest.kt`.
Всего в срезе Task 1 — 36 изменённых/новых файлов UIKit/auth.

Существующие call sites, `BaseScreen` и результаты тестов прочитаны для проверки
совместимости и границ поведения. Новые изменения параллельного Task 2 не входят
в этот вердикт. `backend-yii2/Dockerfile` и `scripts/test_php_runtime.py` принадлежат
отдельной A1 и не использованы как основание замечаний к Task 1. Cart baseline
передан Task 3, как указано ниже.

Работа выполнена без изменения исходников, запуска subagents, commit или push.
Единственный созданный файл — этот отчёт. Dedicated read/glob вернули
`Bun is not defined`; чтение и поиск выполнены через Python fallback.

## Findings по уровням

| Уровень | Количество | Результат |
|---|---:|---|
| Critical | 0 | Не обнаружены |
| High | 0 | Не обнаружены |
| Medium | 0 | Не обнаружены |

Ниже приведены основания положительного вердикта и границы доказательств;
непроверенные runtime-сценарии не выданы за найденные source-дефекты.

## Проверка спецификации и качества

### 1. Палитра, формы и соответствие эскизу

- `core/uikit/src/commonMain/kotlin/com/shopkmp/ui/theme/ShopTokens.kt:5–14`
  содержит заданные teal/warm значения; `Theme.kt:8–54` задаёт Material color
  roles и shapes. `AppShapes.kt:20–31` задаёт primary 12dp, field 10dp и card 14dp.
- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/button/defaultbutton/EpsButton.kt:22–66`
  применяет форму, minimum 48dp для основных/outlined CTA и destructive palette
  ко всем трём типам. Полная ширина auth CTA задаётся реальными callers:
  `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/auth/EpsAuthContent.kt:62–65,113–116,145–149`.
- Изменения card/chip/divider/image/placeholder/text/screen/scaffold и quantity
  остаются presentation-only: скругление, цвета, отступы и доступные подписи.
  Image request/media routing в этом diff не заменяются.
- Композиция Login сопоставлена с `docs/images/frontend-sketch.svg:9–22`,
  Registration — с `:27–49`, PIN — с `:54–118`, app bar/navigation/card/media —
  с `:123–162`. Размеры концептуального SVG не трактовались как требование
  уменьшить touch targets до 38px. Финальный вид на устройствах этим не измерен.

### 2. Совместимость конструкторов и controlled state

- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/button/defaultbutton/EpsButtonState.kt:3–12`
  сохраняет старый полностью позиционный четырёхаргументный конструктор через
  делегирование; callback остаётся последним параметром primary constructor.
  Реальный позиционный caller сохранился в
  `feature/main/src/commonMain/kotlin/com/shopkmp/main/container/MainContainerScreen.kt:73–74,122`.
- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/textfield/defaulttextfield/EpsTextFieldState.kt:7–31`
  сохраняет старые девять позиционных аргументов, добавляя defaulted `readOnly`
  и `keyboardActions`; `onValueChange` остаётся последним.
- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/navigationbar/EpsNavigationItemState.kt:5–13`
  сохраняет старые четыре позиционных аргумента и trailing lambda, а `icon`
  optional. В `EpsNavigationBar.kt:24–38` selection и callback берутся из caller
  state; внутреннего optimistic selection нет. Legacy fallback соответствует
  фактическому порядку Home/Catalog/Cart/Profile в `MainContainerScreen.kt:65–67`.
- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/topbar/EpsTopBarState.kt:3–23`
  сохраняет legacy trailing notifications callback отдельным конструктором.
  Вызов с nullable callback также остаётся допустим через primary constructor.
  Новые теги/actions defaulted, `EpsTopBar` modifier optional.
- `EpsTextField.kt:55–64`, `EpsQuantityStepper.kt:23–25,34–42` и
  `component/pin/EpsPinInput.kt:98–106` под тем же UIKit component root
  используют переданные value/callback/enabled. Нового независимого form,
  quantity, navigation или PIN state внутри controls не введено.

### 3. Теги, editable/read-only, password, IME и ошибки

- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/textfield/defaulttextfield/EpsTextField.kt:45–76`
  размещает caller modifier/test tag непосредственно на `BasicTextField`, а не
  на label или невидимом дубликате. Внешний label очищает собственную semantics,
  доступная подпись и `error` назначены input. Wrapper tag не дублируется.
- Там же `:55–64,77–100` передаются `enabled`, `readOnly`, keyboard options/actions,
  transformation и controlled callback; trailing slot резервирует 48dp,
  supporting error остаётся видимым. Read-only input не подменяется disabled
  подписью или вторым редактируемым контролом.
- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/textfield/passwordtextfield/EpsPasswordTextField.kt:18–40`
  сохраняет парольный keyboard options, masking, validation и caller input tag.
  Visibility action имеет доступную подпись и недоступна при disabled field или
  отсутствии callback. Она меняет caller-owned visibility, а не auth policy.
- Проверки реального ввода/IME, отсутствия SetText у readonly, неинтерактивного
  lock и password error/masking находятся в
  `feature/auth/auth-ui/src/iosTest/kotlin/com/shopkmp/auth/ui/SharedAuthControlsUiTest.kt:44–106`.
  Нативная password semantics при reveal намеренно сохраняется; тест проверяет
  фактический editable text, а не ошибочное требование снять secure semantics.

### 4. App bar, icons и inset ownership

- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/topbar/EpsTopBar.kt:19–64`
  использует настоящий title и IconButton для back/actions; теги прикреплены
  однократно к соответствующим узлам. `enabled` и callback actions соблюдаются;
  back/legacy notifications показываются только при наличии callback.
- Zero app-bar insets в `EpsTopBar.kt:23–24` соответствуют существующему
  `core/mvvm/src/commonMain/kotlin/com/shopkmp/mvvm/BaseScreen.kt:89–106`:
  status spacer расположен перед app bar. Двойной top inset, который давал бы
  Material default, не добавляется. Прямые runtime callers app bar сейчас идут
  через BaseScreen; будущий отдельный chrome должен владеть своим safe area.
- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/navigationbar/EpsNavigationBar.kt:21–47`
  сохраняет Material NavigationBar bottom inset policy и реальные tab actions.
  Иконки декоративны относительно label и не создают отдельные ложные кнопки.
- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/icon/EpsIcon.kt:20–23,33–106`
  реализует конечный локальный набор из 17 Canvas vectors без зависимости или
  wire enum. Action callbacks в новом `EpsTopBarActionState.kt:5–10` обязательны.
  PIN lock в `component/pin/EpsPinInput.kt:72–82` — неинтерактивный индикатор,
  а не фиктивная biometric/unlock action.
- Runtime guards для app-bar tags/callback/disabled action, legacy constructor
  и navigation callback/selection находятся в
  `feature/auth/auth-ui/src/iosTest/kotlin/com/shopkmp/auth/ui/SharedAuthControlsUiTest.kt:110–178`.

### 5. Auth layout, прокрутка и PIN 4–6

- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/auth/EpsAuthContent.kt:47–75,91–117,130–154`
  допускает вертикальную прокрутку Login, Registration и PIN. Login/Registration
  учитывают IME, центрированные Login/PIN используют minimum viewport height,
  а не фиксируют высоту всего содержимого. PIN grid шириной 232dp плюс 48dp
  внешних отступов укладывается в viewport 320dp; вертикальный overflow доступен
  через scroll. Это статическая проверка, не runtime small-screen/keyboard smoke.
- Login сохраняет signup, PIN и conditional biometric alternatives; Registration
  сохраняет все пять полей, consent и submit. Их VM/service callbacks в Task 1
  не изменены. Legacy forgot-password control уже был без обработчика в
  `feature/auth/auth-ui/src/commonMain/kotlin/com/shopkmp/auth/ui/login/LoginViewModel.kt:130–133`;
  новая работа не выдаёт его за реализованную возможность восстановления.
- `feature/auth/auth-ui/src/commonMain/kotlin/com/shopkmp/auth/ui/pin/PinViewModel.kt:32–46,60,113–116,128–175`
  сохраняет policy 4–6, ограничение ввода и explicit submit. Ввод четвёртой цифры
  только делает submit доступным; пятую/шестую по-прежнему можно ввести.
  Единственное изменение этого VM — подпись backspace.
- `core/uikit/src/commonMain/kotlin/com/shopkmp/uikit/component/auth/EpsAuthContent.kt:145–150`
  скрывает submit по переданному `continueButtonState.enabled`, сохраняя
  настоящий callback для валидного PIN.
  `component/pin/EpsPinInput.kt:35–48,55–108` под тем же UIKit root рисует
  расширяемые до шести точки, круглые клавиши и реальный backspace.
- Общий greeting находится в
  `feature/auth/auth-ui/src/commonMain/kotlin/com/shopkmp/auth/ui/pin/PinScreen.kt:52–55`.
  Account lookup/cache, auth payload, lockout/error handling, persistence,
  retries и навигационные правила этим diff не меняются.

## Тестовые доказательства и ограничения

Полный или focused Gradle rerun в этом read-only review не выполнялся:
подтверждённого дефекта, требующего нового targeted proof, не возникло.
Независимо прочитаны существующие XML `build/test-results`. Таблица фиксирует
первый срез review, до обновления артефактов параллельным Task 2:

| Модуль | JVM | iOS simulator |
|---|---:|---:|
| auth-ui | 11 VM | 20 = 11 common/VM + 9 UI |
| profile-ui | 1 | 1 |
| main | 31 | 31 |
| sdui-model | 7 | — |
| sdui-actions | 4 | — |
| sdui-renderer | 2 | 8 |
| **Итого** | **56** | **60** |

В этом первом срезе failures/errors/skips равны нулю. Эти числа — чтение
существующих результатов, а не новые исполнения ревьюера. По
`TASK1_REPORT.md:149–154` model/actions были UP-TO-DATE; это явно квалифицировано
автором, поэтому 56/60 не выдаются за полностью заново исполненные тесты.
Платформенные compile exit 0 приведены автором в `TASK1_REPORT.md:124–154`;
в этом review компиляция повторно не запускалась.

**Конкурентное обновление артефактов:** финальная проверка стабильности суммы
обнаружила уже 67 JVM тестов / 9 failures вместо исходных 56 / 0. XML с timestamp
`2026-10-27T06:22:03.352Z` содержит пять новых EditProfile VM cases (четыре
failures), а `2026-10-27T06:22:08.198Z` — шесть новых SduiViewModel biometric
cases (пять failures). Сравнение имён с `git show b9def939:<test path>` подтвердило,
что все девять failures относятся к добавленным Task 2 cases, а не старым
Task 1 regression tests. Это не заключение о завершённом Task 2: он выполняется
параллельно и находится вне данного review.

Auth XML сохранились с timestamp `06:10:58–06:11:14Z`: 11 JVM VM и 20 iOS
(11 common + 9 UI), без failures/errors/skips. iOS aggregate на финальной сверке
оставался 60 / 0. Проверенный срез из 36 файлов UIKit/auth не менялся; его
совокупный SHA-256 до/после записи отчёта:
`6a3b1d832774d92187ade833d798ec593a6de395ba34c8cd7c8c84a48d7ebdf8`.
Следовательно, 56/60 выше — зафиксированное доказательство Task 1, **не заявление,
что текущий общий workspace целиком GREEN**.

Исторический RED описан в `TASK1_REPORT.md:55–80`: greeting и начальный disabled
submit действительно соответствуют отличиям baseline/нового production diff.
Независимый RED rerun или повторное воспроизведение старого timeout не заявляются.
Существующие VM-тесты/fixtures сохранены; новые сценарии проверяют no-auto-submit,
полный six-digit payload и backspace через generated callback
(`feature/auth/auth-ui/src/commonTest/kotlin/com/shopkmp/auth/ui/pin/PinViewModelTest.kt:75–122`).
Изменение исходного UI-fixture делает PIN валидным и сохраняет прежние assertions
цифры/submit; переход email test к реальному input tag не ослабляет ввод
(`feature/auth/auth-ui/src/iosTest/kotlin/com/shopkmp/auth/ui/AuthScreenUiTest.kt:42–77`).

Дополнительный cart XML содержит 2 теста и 1 failure на ожидаемом
`Итого: 100,00 ₽`. Источник baseline mismatch (`100 RUB`) и неизменность money/cart
файлов описаны в `TASK1_REPORT.md:156–172`. В Task 1 diff нет изменения
currency formatter/cart total; доказательств регрессии Task 1 не найдено.
Этот результат остаётся явным handoff Task 3 и не включён в зелёные 56/60.

`git diff --check -- core/uikit feature/auth/auth-ui` выполнен в review: exit 0.
Installed-app captures, VoiceOver/Dynamic Type и runtime inset/IME/small-screen
проверки здесь не выполнялись. Их отсутствие не скрыто положительным code review.
