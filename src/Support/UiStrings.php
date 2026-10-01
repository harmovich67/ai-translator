<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Support;

/**
 * Interface strings of the popup, in Arabic and English (English is the fallback for every other language).
 */
final class UiStrings
{
    public static function all(string $lang): array
    {
        $en = [
            'toggle' => 'Translate',
            'topbar' => 'Live translate',
            'toggleOn' => 'Translate mode is on. Click any text. Hold Ctrl to follow links.',
            'toggleOff' => 'Translate mode is off.',
            'title' => 'Translate text',
            'original' => 'Text on the page',
            'languages' => 'Translations',
            'where' => 'Where it will be saved',
            'selectAll' => 'Select all',
            'openEditor' => 'Open in editor',
            'noSource' => 'This text is not stored in the database (it is typed in the page template), it will be saved as a site-wide translation.',
            'alsoDictionary' => 'Also save as a site-wide translation for this exact text',
            'save' => 'Save',
            'cancel' => 'Cancel',
            'saving' => 'Saving…',
            'saved' => 'Saved.',
            'loading' => 'Looking for the text…',
            'error' => 'Something went wrong. Please try again.',
            'nothingToSave' => 'Select at least one place to save, or fill in a translation.',
            'hint' => 'Ctrl + Enter to save · Esc to close',
            'clickToTranslate' => 'Click to translate',
            'empty' => 'empty = keeps what is stored',
            'type_field' => 'Content',
            'type_line' => 'List item / paragraph',
            'type_setting' => 'Setting',
            'type_lang' => 'Static text',
            'aiAll' => 'Translate with Gemini',
            'aiField' => 'Translate this language with Gemini',
            'aiWorking' => 'Translating…',
            'aiNoSource' => 'Write the text in one language first.',
            'aiNothing' => 'Every language already has a text. Use the ✨ next to a language to translate it again.',
            'aiDone' => 'Translated with Gemini. Read it, then save.',
            'ai_off' => 'Gemini is turned off. Turn it on from the Live translate page of the dashboard.',
            'ai_no-key' => 'No Gemini API key is saved. Add it on the Live translate page of the dashboard.',
            'ai_bad-key' => 'Google refused the API key. Check it on the Live translate page.',
            'ai_model' => 'Google does not know this model. Check the model name on the Live translate page.',
            'ai_quota' => 'The Gemini quota is used up for now. Try again in a minute.',
            'ai_network' => 'Could not reach Google. Check the internet connection of the server.',
            'ai_blocked' => 'Gemini declined to translate this text.',
            'ai_bad-response' => 'Gemini sent an answer that could not be used. Try again.',
            'ai_bad-request' => 'There is nothing to translate.',
            'ai_api' => 'Gemini returned an error.',
            'pageToggle' => 'Translate page',
            'pageTitle' => 'Translate the whole page',
            'pageScanning' => 'Looking for text on this page…',
            'pageTranslating' => 'Translating with Gemini…',
            'pageNothing' => 'Nothing on this page needs translation.',
            'pageCount' => '{n} texts need translation',
            'pageSelectAll' => 'Select all',
            'pageDeselectAll' => 'Deselect all',
            'pageSaveSelected' => 'Save selected',
            'pageSaving' => 'Saving…',
            'pageDone' => 'Saved {ok} of {total}.',
            'pageTruncated' => 'Only the first {n} texts of this page are shown.',
            'pageFailedRow' => 'Gemini could not translate this one; write it by hand.',
            'pageMissing' => 'Missing: {langs}',
        ];

        $ar = [
            'toggle' => 'ترجمة',
            'topbar' => 'ترجمة فورية',
            'toggleOn' => 'وضع الترجمة مفعّل. اضغط على أي نص. اضغط Ctrl مع الرابط للتنقل.',
            'toggleOff' => 'تم إيقاف وضع الترجمة.',
            'title' => 'ترجمة النص',
            'original' => 'النص في الصفحة',
            'languages' => 'الترجمات',
            'where' => 'مكان الحفظ',
            'selectAll' => 'تحديد الكل',
            'openEditor' => 'فتح في المحرر',
            'noSource' => 'هذا النص غير مخزّن في قاعدة البيانات (مكتوب داخل قالب الصفحة)، وسيُحفظ كترجمة عامة للموقع.',
            'alsoDictionary' => 'احفظه أيضًا كترجمة عامة لهذا النص بالتحديد',
            'save' => 'حفظ',
            'cancel' => 'إلغاء',
            'saving' => 'جارٍ الحفظ…',
            'saved' => 'تم الحفظ.',
            'loading' => 'جارٍ البحث عن النص…',
            'error' => 'حدث خطأ. حاول مرة أخرى.',
            'nothingToSave' => 'اختر مكانًا واحدًا للحفظ على الأقل، أو اكتب ترجمة.',
            'hint' => 'Ctrl + Enter للحفظ · Esc للإغلاق',
            'clickToTranslate' => 'اضغط للترجمة',
            'empty' => 'فارغ = يبقى المخزّن كما هو',
            'type_field' => 'محتوى',
            'type_line' => 'بند / فقرة',
            'type_setting' => 'إعداد',
            'type_lang' => 'نص ثابت',
            'aiAll' => 'ترجمة بـ Gemini',
            'aiField' => 'ترجمة هذه اللغة بـ Gemini',
            'aiWorking' => 'جارٍ الترجمة…',
            'aiNoSource' => 'اكتب النص بلغة واحدة أولًا.',
            'aiNothing' => 'كل اللغات فيها نص بالفعل. استخدم ✨ بجانب اللغة لإعادة ترجمتها.',
            'aiDone' => 'تمت الترجمة بـ Gemini. راجع النص ثم احفظ.',
            'ai_off' => 'ترجمة Gemini متوقفة. فعّلها من صفحة «الترجمة الفورية» في لوحة التحكم.',
            'ai_no-key' => 'لم يُحفظ مفتاح Gemini. أضفه من صفحة «الترجمة الفورية» في لوحة التحكم.',
            'ai_bad-key' => 'رفضت Google مفتاح الـ API. راجعه في صفحة «الترجمة الفورية».',
            'ai_model' => 'Google لا تعرف هذا الموديل. راجع اسم الموديل في صفحة «الترجمة الفورية».',
            'ai_quota' => 'انتهت حصة Gemini مؤقتًا. حاول بعد دقيقة.',
            'ai_network' => 'تعذّر الاتصال بـ Google. تأكد من اتصال السيرفر بالإنترنت.',
            'ai_blocked' => 'رفض Gemini ترجمة هذا النص.',
            'ai_bad-response' => 'أرجع Gemini ردًا غير صالح للاستخدام. حاول مرة أخرى.',
            'ai_bad-request' => 'لا يوجد نص للترجمة.',
            'ai_api' => 'أرجع Gemini خطأ.',
            'pageToggle' => 'ترجمة الصفحة',
            'pageTitle' => 'ترجمة الصفحة كاملة',
            'pageScanning' => 'جارٍ البحث عن النصوص في هذه الصفحة…',
            'pageTranslating' => 'جارٍ الترجمة بـ Gemini…',
            'pageNothing' => 'لا يوجد في هذه الصفحة نص يحتاج ترجمة.',
            'pageCount' => '{n} نص يحتاج ترجمة',
            'pageSelectAll' => 'تحديد الكل',
            'pageDeselectAll' => 'إلغاء تحديد الكل',
            'pageSaveSelected' => 'حفظ المحدد',
            'pageSaving' => 'جارٍ الحفظ…',
            'pageDone' => 'تم حفظ {ok} من {total}.',
            'pageTruncated' => 'يعرض هذا أول {n} نص فقط من الصفحة.',
            'pageFailedRow' => 'تعذّرت الترجمة الآلية لهذا النص، اكتبه يدويًا.',
            'pageMissing' => 'ناقص: {langs}',
        ];

        return strtolower(substr($lang, 0, 2)) === 'ar' ? $ar : $en;
    }

    public static function get(string $key, string $lang): string
    {
        return self::all($lang)[$key] ?? $key;
    }
}
