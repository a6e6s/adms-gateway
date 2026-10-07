<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="بوابة ADMS من JIT لجمع سجلات الحضور من أجهزة ZKTeco، إدارة الأجهزة، وربط بيانات الحضور بأنظمة الموارد البشرية.">
    <meta name="theme-color" content="#009d99">
    <title>JIT | بوابة ADMS لإدارة بيانات الحضور</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-landing-page class="bg-[#f7f9f8] text-slate-800 antialiased">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:right-4 focus:z-50 focus:bg-white focus:p-4">انتقل إلى المحتوى</a>
<div data-scroll-progress class="pointer-events-none fixed inset-x-0 top-0 z-50 h-1 origin-right bg-teal-500" aria-hidden="true"></div>
<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-5 px-6 py-5 lg:px-10">
        <a href="{{ route('home') }}" class="flex items-center gap-4" aria-label="JIT — الرئيسية">
            <img src="{{ asset('images/jit-logo.png') }}" alt="شعار JIT" width="1380" height="502" class="h-10 w-auto">
            <span class="border-r border-slate-200 pr-4 text-sm font-semibold">بوابة الحضور <span dir="ltr" class="block text-xs tracking-widest text-slate-500">ADMS GATEWAY</span></span>
        </a>
        <nav aria-label="القائمة الرئيسية" class="flex flex-wrap items-center gap-5 text-sm">
            <a href="#features" class="hover:text-teal-700">المميزات</a><a href="#workflow" class="hover:text-teal-700">آلية العمل</a><a href="#about" class="hover:text-teal-700">عن JIT</a><a href="#faq" class="hover:text-teal-700">الأسئلة الشائعة</a>
            <a href="{{ route('filament.admin.auth.login') }}" class="rounded-full bg-teal-700 px-5 py-3 font-semibold text-white hover:bg-teal-800">دخول المنصة ↗</a>
        </nav>
    </div>
</header>
<main id="main">
    <section class="relative overflow-hidden border-b border-slate-200 bg-white">
        <div data-hero-glow class="absolute -left-32 top-0 h-96 w-96 rounded-full bg-teal-100/60 blur-3xl" aria-hidden="true"></div>
        <div class="relative mx-auto grid max-w-7xl items-center gap-14 px-6 py-20 lg:grid-cols-2 lg:px-10 lg:py-28">
            <div data-hero-copy>
                <p class="mb-6 flex items-center gap-2 text-sm font-semibold text-teal-700"><span class="h-2 w-2 rounded-full bg-teal-600"></span> بيانات موثوقة. قرارات أوضح.</p>
                <h1 class="text-4xl leading-tight font-bold tracking-tight sm:text-5xl lg:text-6xl">كل أجهزة الحضور.<br><span class="text-teal-700">في بوابة واحدة.</span></h1>
                <p class="mt-7 max-w-xl text-lg leading-9 text-slate-600">حوّل سجلات أجهزة البصمة إلى بيانات حضور منظمة يمكن لفريقك الوصول إليها وربطها بأنظمة الموارد البشرية. بوابة ADMS تجمع البيانات، تحفظ مصدرها، وتمنحك رؤية واضحة للأجهزة والموظفين.</p>
                <div class="mt-9 flex flex-wrap gap-3"><a href="{{ route('filament.admin.auth.login') }}" class="rounded-full bg-teal-700 px-7 py-4 font-semibold text-white hover:bg-teal-800">الدخول إلى لوحة الإدارة ↗</a><a href="#workflow" class="rounded-full border border-slate-300 px-7 py-4 font-semibold hover:bg-slate-50">اكتشف كيف تعمل</a></div>
                <p class="mt-7 text-sm text-slate-500">أجهزة ZKTeco المتوافقة مع ADMS / iClock PUSH</p>
            </div>
            <div data-hero-panel class="rounded-3xl border border-slate-200 bg-[#f4f8f7] p-5 shadow-xl shadow-teal-900/5 sm:p-8">
                <div class="mb-6 flex items-center justify-between"><span class="font-semibold">من الجهاز إلى نظامك</span><span class="rounded-full bg-white px-3 py-1 text-xs text-slate-500">رسم توضيحي</span></div>
                <div class="rounded-2xl bg-[#143d3d] p-7 text-white">
                    <div class="flex items-start justify-between"><div><span class="text-xs tracking-widest text-teal-200" dir="ltr">ADMS GATEWAY</span><h2 class="mt-2 text-2xl font-semibold">الحضور، بصورة أوضح</h2></div><span class="text-teal-300" aria-hidden="true"><x-heroicon-o-finger-print class="h-10 w-10" /></span></div>
                    <div class="mt-7 grid grid-cols-3 gap-3 text-center text-sm"><div class="rounded-xl bg-white/10 p-4"><x-heroicon-o-device-tablet class="mx-auto mb-3 h-6 w-6 text-teal-200" aria-hidden="true" />الأجهزة</div><div class="rounded-xl bg-white/10 p-4"><x-heroicon-o-users class="mx-auto mb-3 h-6 w-6 text-teal-200" aria-hidden="true" />الموظفون</div><div class="rounded-xl bg-white/10 p-4"><x-heroicon-o-clipboard-document-check class="mx-auto mb-3 h-6 w-6 text-teal-200" aria-hidden="true" />السجلات</div></div>
                </div>
                <div class="my-4 flex justify-center text-teal-600" aria-hidden="true"><x-heroicon-o-arrow-down class="h-6 w-6" /></div>
                <div class="space-y-3">
                    @foreach (['أجهزة الحضور' => 'إرسال سجلات البصمة عبر PUSH', 'بوابة ADMS' => 'حفظ المصدر وتنظيم سجلات الحضور', 'أنظمة الموارد البشرية' => 'قراءة البيانات عبر واجهة API'] as $title => $description)
                        <div data-flow-step class="flex items-center gap-4 rounded-xl border border-slate-200 bg-white p-4"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-teal-50 text-sm font-bold text-teal-700"><x-dynamic-component :component="['heroicon-o-device-tablet', 'heroicon-o-circle-stack', 'heroicon-o-code-bracket'][$loop->index]" class="h-5 w-5" aria-hidden="true" /></span><div><h3 class="font-semibold">{{ $title }}</h3><p class="mt-1 text-sm text-slate-500">{{ $description }}</p></div></div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
    <div class="border-b border-slate-200 bg-[#edf4f2]"><div class="mx-auto grid max-w-7xl gap-5 px-6 py-7 text-center text-sm font-semibold sm:grid-cols-3"><p>01 / جمع بيانات الأجهزة</p><p>02 / إدارة ومتابعة الحضور</p><p>03 / التكامل مع أنظمة HR و ERP</p></div></div>
    <section id="features" class="mx-auto max-w-7xl scroll-mt-8 px-6 py-24 lg:px-10">
        <div data-reveal class="mb-12 max-w-2xl"><p class="text-sm font-semibold text-teal-700">مميزات البوابة</p><h2 class="mt-4 text-3xl leading-normal font-bold sm:text-4xl">من سجلات متفرقة إلى مصدر منظم للحضور</h2><p class="mt-5 leading-8 text-slate-600">أدوات عملية لجمع بيانات الحضور ومراجعتها، مع الاحتفاظ بالسجل الأصلي لكل إرسال من الجهاز.</p></div>
        <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
            @foreach ([['جمع تلقائي من الأجهزة', 'استقبال سجلات الحضور من أجهزة ZKTeco المسجلة عبر بروتوكول ADMS / iClock PUSH، وربط كل جهاز بالشركة التابعة له.'], ['حفظ البيانات الأصلية', 'الاحتفاظ بمحتوى الإرسال الأصلي ووقت الاستلام وحالة المعالجة، لتسهيل المراجعة وتتبع مصدر كل سجل.'], ['الحد من تكرار السجلات', 'معالجة الإرسالات المتكررة دون إضافة بصمات مطابقة مرة أخرى، مع الاحتفاظ بإيصالات الإرسال للمراجعة.'], ['إدارة الموظفين والأجهزة', 'تنظيم الشركات والأجهزة والموظفين، وربط رقم الموظف على الجهاز بهويته في النظام.'], ['متابعة المعالجة والاستعادة', 'عرض السجلات المقبولة والمكررة والمرفوضة، وإعادة محاولة معالجة الإرسالات الفاشلة أو التي تحتوي على أخطاء.'], ['واجهة لتكامل الحضور', 'إتاحة قراءة معاملات الحضور عبر API بنمط BioTime، مع تصفية حسب الموظف والجهاز والفترة الزمنية.']] as [$title, $description])
                <article data-reveal class="landing-card rounded-2xl border border-slate-200 bg-white p-7"><span class="mb-6 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-teal-50 text-teal-700"><x-dynamic-component :component="['heroicon-o-signal', 'heroicon-o-circle-stack', 'heroicon-o-shield-check', 'heroicon-o-users', 'heroicon-o-arrow-path', 'heroicon-o-code-bracket'][$loop->index]" class="h-6 w-6" aria-hidden="true" /></span><h3 class="text-xl font-bold">{{ $title }}</h3><p class="mt-4 leading-8 text-slate-600">{{ $description }}</p></article>
            @endforeach
        </div>
    </section>
    <section id="workflow" class="scroll-mt-8 bg-[#143d3d] text-white">
        <div class="mx-auto max-w-7xl px-6 py-24 lg:px-10"><p class="text-sm text-teal-200">آلية العمل</p><h2 class="mt-4 text-3xl font-bold sm:text-4xl">رحلة بيانات الحضور، خطوة بخطوة</h2><div class="mt-14 grid gap-10 md:grid-cols-2 lg:grid-cols-4">
            @foreach ([['تسجيل الأجهزة', 'تحديد الشركة ورقم الجهاز التسلسلي والمنطقة الزمنية، وضبط إعدادات الاتصال بالبوابة.'], ['استقبال السجلات', 'يرسل الجهاز بيانات الحضور، وتحفظ البوابة نسخة أصلية قبل تأكيد استلامها.'], ['تنظيم ومراجعة', 'تعالج البوابة البيانات في الخلفية، وتربطها بالموظفين وتعرض النتائج وحالات المعالجة.'], ['ربط نظامك', 'يقرأ نظام الموارد البشرية سجلات الحضور عبر API ليستخدمها في عملياته وحساباته.']] as [$title, $description])
                <article data-reveal class="relative border-t border-white/20 pt-6"><x-dynamic-component :component="['heroicon-o-device-tablet', 'heroicon-o-cloud-arrow-down', 'heroicon-o-adjustments-horizontal', 'heroicon-o-link'][$loop->index]" class="absolute top-6 left-0 h-8 w-8 text-teal-200/60" aria-hidden="true" /><span class="text-4xl font-light text-teal-300" dir="ltr">0{{ $loop->iteration }}</span><h3 class="mt-6 text-xl font-semibold">{{ $title }}</h3><p class="mt-4 leading-8 text-teal-50/75">{{ $description }}</p></article>
            @endforeach
        </div></div>
    </section>
    <section class="mx-auto grid max-w-7xl gap-12 px-6 py-24 lg:grid-cols-2 lg:px-10">
        <div><p class="text-sm font-semibold text-teal-700">مصممة لفرق التشغيل والتكامل</p><h2 class="mt-4 text-3xl leading-normal font-bold sm:text-4xl">رؤية مشتركة بين الموارد البشرية وتقنية المعلومات</h2><p class="mt-6 leading-8 text-slate-600">لوحة إدارة تدعم العربية والإنجليزية، تجمع معلومات الأجهزة والموظفين وسجلات الحضور في مكان واحد. راجع ما وصل من الأجهزة، وتتبع الإرسالات التي تحتاج إلى معالجة.</p><a href="{{ route('filament.admin.auth.login') }}" class="mt-7 inline-block font-semibold text-teal-700 hover:underline">افتح لوحة الإدارة ←</a></div>
        <div class="grid gap-4">
            @foreach (['لفريق الموارد البشرية' => 'الوصول إلى سجلات الموظفين والأوقات والأجهزة المرتبطة بها، لتوفير البيانات اللازمة للأنظمة المتخصصة.', 'لفريق التشغيل' => 'متابعة آخر اتصال للأجهزة، مراجعة الإرسالات، وطلب سجلات حضور تاريخية من الأجهزة المدعومة.', 'لفريق التكامل' => 'قراءة معاملات خاصة بالشركة باستخدام حسابات API منفصلة عن حسابات الدخول إلى لوحة الإدارة.'] as $title => $description)
            <article data-reveal class="landing-card rounded-2xl border border-slate-200 bg-white p-6"><x-dynamic-component :component="['heroicon-o-user-group', 'heroicon-o-cog-6-tooth', 'heroicon-o-command-line'][$loop->index]" class="mb-4 h-7 w-7 text-teal-700" aria-hidden="true" /><h3 class="font-bold">{{ $title }}</h3><p class="mt-3 leading-8 text-slate-600">{{ $description }}</p></article>
            @endforeach
        </div>
    </section>
    <section id="about" class="scroll-mt-8 border-y border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-6 py-24 lg:px-10"><div class="grid gap-12 lg:grid-cols-2"><div><img src="{{ asset('images/jit-logo.png') }}" alt="JIT" width="1380" height="502" loading="lazy" class="mb-8 h-16 w-auto"><p class="text-sm font-semibold text-teal-700">عن JIT</p><h2 class="mt-4 text-3xl leading-normal font-bold sm:text-4xl">الإنسان في قلب التطور</h2></div><div><p class="text-lg leading-9 text-slate-600">تقدم JIT، بحسب موقعها الرسمي، الاستشارات والحلول التقنية في مجال الموارد البشرية. وتضع تمكين المؤسسات وتطوير بيئات العمل في صميم رسالتها.</p><p class="mt-5 leading-8 text-slate-600">تعكس قيمها التركيز على الشراكة والابتكار والنزاهة والتميز. ويساعد تنظيم بيانات الحضور على توفير أساس أوضح لعمل فرق الموارد البشرية.</p><a href="https://jit.sa" target="_blank" rel="noopener noreferrer" class="mt-6 inline-block font-semibold text-teal-700 hover:underline">تعرف على JIT عبر الموقع الرسمي ↗</a></div></div>
        <div class="mt-14 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">@foreach (['الشراكة', 'الابتكار', 'النزاهة', 'التميز'] as $value)<div data-reveal class="rounded-xl bg-[#f0f6f4] px-6 py-5 text-center font-semibold text-teal-800"><x-dynamic-component :component="['heroicon-o-hand-raised', 'heroicon-o-light-bulb', 'heroicon-o-shield-check', 'heroicon-o-sparkles'][$loop->index]" class="mx-auto mb-3 h-7 w-7" aria-hidden="true" />{{ $value }}</div>@endforeach</div>
        </div>
    </section>
    <section id="faq" class="mx-auto max-w-4xl scroll-mt-8 px-6 py-24">
        <p class="text-center text-sm font-semibold text-teal-700">الأسئلة الشائعة</p><h2 class="mt-4 text-center text-3xl font-bold sm:text-4xl">تفاصيل تساعدك على البدء</h2>
        <div class="mt-12 divide-y divide-slate-200 border-y border-slate-200">
            @foreach ([['ما الأجهزة التي تدعمها البوابة؟', 'البوابة تستقبل سجلات ATTLOG من أجهزة ZKTeco التي تستخدم بروتوكول ADMS / iClock PUSH. يجب التحقق من توافق طراز الجهاز وإصدار البرنامج الثابت قبل الاستخدام.'], ['هل تقوم البوابة بحساب الرواتب والعمل الإضافي؟', 'تجمع البوابة أدلة الحضور وتنظمها. يتولى نظام الموارد البشرية أو ERP المتصل حساب الورديات وساعات العمل والإجازات والرواتب وفق قواعد المؤسسة.'], ['كيف أستفيد من واجهة API؟', 'يمكن لنظامك قراءة قائمة معاملات الحضور وتفاصيلها باستخدام حساب API خاص بالشركة، مع تصفية النتائج حسب الموظف أو الجهاز أو الفترة الزمنية.'], ['هل تدعم جميع وظائف BioTime؟', 'تتوفر قراءة معاملات الحضور ومصادقة الرمز العام بنمط BioTime. التوافق الكامل مع الإصدارات المختلفة ومصادقة JWT لا يزالان قيد التطوير؛ راجع متطلبات نظامك قبل الربط.'], ['ماذا يحدث عند إعادة إرسال البيانات؟', 'تحفظ البوابة إيصال الإرسال الجديد، وتمنع إنشاء بصمات مطابقة مكررة عند تنظيم البيانات. يمكن مراجعة أعداد السجلات المكررة وحالة المعالجة من لوحة الإدارة.'], ['هل يمكن إدارة أكثر من شركة؟', 'تسمح البوابة بربط الأجهزة والموظفين بالشركات وتقييد بيانات API بالشركة. يجب إعداد صلاحيات لوحة الإدارة ومراجعتها قبل منح الوصول إلى مستخدمي شركات مختلفة.']] as [$question, $answer])
            <details class="group py-6"><summary class="flex cursor-pointer items-center justify-between gap-5 text-lg font-semibold"><span>{{ $question }}</span><x-heroicon-o-plus class="faq-plus h-5 w-5 shrink-0 text-teal-700" aria-hidden="true" /></summary><p class="mt-4 leading-8 text-slate-600">{{ $answer }}</p></details>
            @endforeach
        </div>
    </section>
    <section class="px-6 pb-24"><div data-reveal class="mx-auto max-w-7xl rounded-3xl bg-teal-700 px-8 py-16 text-center text-white sm:px-16"><p class="text-sm text-teal-100">ابدأ من بياناتك</p><h2 class="mt-4 text-3xl leading-normal font-bold sm:text-4xl">امنح بيانات الحضور مكانًا واحدًا واضحًا</h2><p class="mx-auto mt-5 max-w-2xl leading-8 text-teal-50">ادخل إلى لوحة الإدارة لمتابعة أجهزتك وسجلات الحضور، أو تواصل مع JIT للتعرف على خدمات الموارد البشرية.</p><div class="mt-8 flex flex-wrap justify-center gap-4"><a href="{{ route('filament.admin.auth.login') }}" class="rounded-full bg-white px-7 py-4 font-semibold text-teal-800 hover:bg-teal-50">دخول المنصة ↗</a><a href="https://jit.sa/contact" target="_blank" rel="noopener noreferrer" class="rounded-full border border-white/50 px-7 py-4 font-semibold hover:bg-white/10">تواصل مع JIT</a></div></div></section>
</main>
<footer class="border-t border-slate-200 bg-white"><div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-8 px-6 py-10 lg:px-10"><div><img src="{{ asset('images/jit-logo.png') }}" alt="JIT" width="1380" height="502" loading="lazy" class="h-9 w-auto"><p class="mt-4 text-sm text-slate-500">بوابة ADMS · بيانات حضور منظمة لعمليات أكثر وضوحًا.</p></div><nav aria-label="روابط التذييل" class="flex flex-wrap gap-6 text-sm"><a href="#features" class="hover:text-teal-700">المميزات</a><a href="#faq" class="hover:text-teal-700">الأسئلة الشائعة</a><a href="https://jit.sa" class="hover:text-teal-700" target="_blank" rel="noopener noreferrer">الموقع الرسمي لـ JIT ↗</a></nav><p class="w-full border-t border-slate-100 pt-6 text-xs text-slate-500">© {{ date('Y') }} JIT · معلومات الشركة وشعارها من <a href="https://jit.sa" class="underline">jit.sa</a></p></div></footer>
</body>
</html>
