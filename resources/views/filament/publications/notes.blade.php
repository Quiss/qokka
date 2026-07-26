<div class="flex flex-col gap-6">
    <section class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Два поля решают разные задачи</h3>
        <div class="grid gap-3 md:grid-cols-2">
            <div class="rounded-lg bg-primary-50 p-4 dark:bg-primary-500/10">
                <p class="font-semibold text-primary-950 dark:text-primary-100">Инструкция отбора новостей</p>
                <p class="mt-2 text-sm leading-6 text-primary-900 dark:text-primary-200">
                    Определяет, какие инфоповоды подходят каналу. Здесь задаются тема, география, польза для аудитории, обязательные признаки и исключения.
                </p>
            </div>
            <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/5">
                <p class="font-semibold text-gray-950 dark:text-white">Редакционная инструкция для AI</p>
                <p class="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-200">
                    Определяет, как переписать уже выбранную новость: тон, длину, структуру, юмор, эмодзи, акценты и правила для серьёзных тем.
                </p>
            </div>
        </div>
    </section>

    <section class="flex flex-col gap-4">
        <div>
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Как разделить каналы с общей базой источников</h3>
            <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">
                Сначала сформулируйте главный вопрос каждого канала, затем явно исключите материалы соседнего канала.
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <article class="flex min-w-0 flex-col gap-3 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div>
                    <h4 class="font-semibold text-gray-950 dark:text-white">Новости Питера</h4>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Главный вопрос: «Что произошло в городе сейчас?»</p>
                </div>
                <div class="whitespace-pre-line rounded-lg bg-gray-950 p-4 font-mono text-xs leading-6 text-gray-100">Отбирай оперативные новости, непосредственно связанные с Санкт-Петербургом и влияющие на жизнь города или его жителей: происшествия, транспорт, решения властей, ЖКХ, погоду, городские события, экономику и общественную жизнь.

Если текст короткий и не называет город, учитывай название локального источника и петербургские топонимы как контекст. Если текст явно относится к другому региону, название источника не должно перевешивать факты поста.

Не отбирай путеводители, подборки мест, обзоры заведений, прогулочные маршруты и материалы, основная ценность которых — рекомендация, куда сходить. Не включай федеральные новости без явного влияния на Петербург.</div>
            </article>

            <article class="flex min-w-0 flex-col gap-3 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div>
                    <h4 class="font-semibold text-gray-950 dark:text-white">Интересные места в Питере</h4>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Главный вопрос: «Куда сходить и почему это интересно?»</p>
                </div>
                <div class="whitespace-pre-line rounded-lg bg-gray-950 p-4 font-mono text-xs leading-6 text-gray-100">Отбирай материалы об интересных местах Санкт-Петербурга: достопримечательностях, архитектуре, парках, музеях, выставках, смотровых площадках, необычных пространствах, заведениях и прогулочных маршрутах.

Новость должна помогать читателю понять, куда можно сходить, что там посмотреть и почему место заслуживает внимания. Допускаются открытия, реставрации и новые выставки, если главная ценность материала — возможность посетить место.

Не отбирай ДТП, преступления, коммунальные аварии, политику, обычные транспортные новости и происшествия. Самого факта, что событие произошло в Петербурге, недостаточно.</div>
            </article>
        </div>

        <div class="rounded-lg border border-warning-200 bg-warning-50 p-4 text-sm leading-6 text-warning-900 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-100">
            Пограничный пример: открытие нового музея может быть городской новостью или рекомендацией места. Зафиксируйте приоритет прямо в инструкциях. Один исходный пост может попасть в разные каналы, если подходит обеим инструкциям.
        </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-2">
        <article class="flex flex-col gap-3 rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Шаблон инструкции отбора</h3>
            <ol class="list-decimal space-y-2 pl-5 text-sm leading-6 text-gray-700 dark:text-gray-200">
                <li>Кому предназначен канал и какую пользу получает читатель.</li>
                <li>Какие темы и география обязательны.</li>
                <li>Какие признаки делают новость подходящей.</li>
                <li>Какие темы нужно исключить, даже если они популярны.</li>
                <li>Как разбирать короткие посты и пограничные случаи.</li>
            </ol>
        </article>

        <article class="flex flex-col gap-3 rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Шаблон редакционной инструкции</h3>
            <ol class="list-decimal space-y-2 pl-5 text-sm leading-6 text-gray-700 dark:text-gray-200">
                <li>Кто автор и для какой аудитории он пишет.</li>
                <li>Как начинать пост и какой объём использовать.</li>
                <li>Какой тон допустим для обычных и серьёзных тем.</li>
                <li>Сколько использовать абзацев, эмодзи, цитат и шуток.</li>
                <li>Какие формулировки, кликбейт и домыслы запрещены.</li>
            </ol>
        </article>
    </section>

    <section class="flex flex-col gap-3">
        <div>
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Пример редакционной инструкции</h3>
            <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">Адаптируйте объём и характер подачи под свой канал.</p>
        </div>
        <div class="whitespace-pre-line rounded-lg bg-gray-950 p-4 font-mono text-xs leading-6 text-gray-100">Пиши как редактор живого городского Telegram-канала. Начинай сразу с главного факта и не повторяй его в первом абзаце другими словами.

Используй 2–4 коротких абзаца. Сохраняй важные имена, адреса, даты, цены и цифры. Тон разговорный, ясный и уверенный, без канцелярита и кликбейта.

Для необычных событий допустима одна точная шутка или лёгкая ирония. Для ДТП, конфликтов, смертей и других серьёзных происшествий пиши сдержанно, без юмора и обесценивания.

Используй не больше одного тематического эмодзи. Не выдумывай причины, реакции людей, цитаты и дополнительные факты. Если уместного вывода нет, закончи на самом сильном подтверждённом факте.</div>
    </section>
</div>
