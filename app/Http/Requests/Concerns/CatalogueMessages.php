<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\Msg;
use Illuminate\Support\Str;

/**
 * TSD §2.3, stage 2 — "returning MSG-xxx codes rather than prose, so wording
 * lives in one language file".
 *
 * Laravel already keeps the sentence out of the controller: a message array here
 * replaces the framework's English defaults, and the catalogue in FSD §2.6
 * already says what a missing mandatory field and a wrong format sound like.
 * What this trait adds is the discipline of never writing a validation sentence
 * inline: a request names its fields, and the two catalogue codes do the talking.
 *
 * MSG-030 ("Field bertanda * wajib diisi.") is the sentence the FSD gives for a
 * submit with gaps, and it does not name the field — the form's own markup puts
 * the error under the input, which is how §2.5 wants it read. MSG-031
 * ("Format tidak sesuai: {field} {rule}.") is the one that does name it, so
 * :attribute and the localised rule name are passed in as its placeholders.
 *
 * Custom rule objects (PhoneNumber, CorporateEmailDomain, the password rules)
 * still carry their own sentences, and that is right: their failure is a fact
 * about the domain rather than about the shape of the input, and §2.6 has no code
 * for them.
 */
trait CatalogueMessages
{
    /**
     * The rules that mean "the value is the wrong shape", as opposed to "the
     * value is missing". Anything that fails one of these is an MSG-031.
     *
     * @var list<string>
     */
    private const FORMAT_RULES = [
        'accepted', 'after', 'after_or_equal', 'alpha', 'array', 'before',
        'before_or_equal', 'boolean', 'confirmed', 'date', 'digits', 'email',
        'exists', 'gt', 'gte', 'in', 'integer', 'lt', 'lte', 'max', 'mimes',
        'min', 'not_in', 'numeric', 'present', 'prohibited', 'regex', 'same',
        'size', 'string', 'unique', 'url',
    ];

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $rules = $this->rules();

        // Two key forms, deliberately.
        //
        // The dotted "field.rule" keys are the precise ones: the validator looks
        // a message up as ["{attribute}.{rule}", "{rule}", "{attribute}"], so a
        // dotted key answers for one field and nothing else, and MSG-031's
        // {field} placeholder gets the label of the field that actually failed.
        //
        // The bare rule keys are the net for a rule this form does not name a
        // field for. They are skipped for any rule whose name is also the name of
        // a field in this request — 'email' being exactly that case. A bare
        // 'email' entry is read by the same lookup as "the message for the
        // attribute called email", which would put the format sentence on a
        // refusal that came from the corporate-domain rule and drop the V-101
        // sentence the rule itself carries. That is not a hypothetical: it is what
        // the first version of this trait did, and it is why the two forms are
        // separated here.
        $messages = [];

        foreach ($rules as $attribute => $attributeRules) {
            foreach ((array) $attributeRules as $rule) {
                if (! is_string($rule)) {
                    // A rule object is its own sentence: PhoneNumber,
                    // CorporateEmailDomain and the password rules state a fact
                    // about the domain, and §2.6 has no code for those.
                    continue;
                }

                $name = Str::lower(Str::before($rule, ':'));

                if (str_starts_with($name, 'required')) {
                    $messages["{$attribute}.{$name}"] = Msg::get('MSG-030');

                    continue;
                }

                if (in_array($name, self::FORMAT_RULES, true)) {
                    $messages["{$attribute}.{$name}"] = Msg::get('MSG-031', [
                        'field' => ':attribute',
                        'rule' => $this->ruleName($name),
                    ]);
                }
            }
        }

        $fields = array_keys($rules);

        $messages['required'] = Msg::get('MSG-030');

        foreach (self::FORMAT_RULES as $rule) {
            if (in_array($rule, $fields, true)) {
                continue;
            }

            $messages[$rule] = Msg::get('MSG-031', [
                'field' => ':attribute',
                'rule' => $this->ruleName($rule),
            ]);
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->fieldLabels();
    }

    /**
     * The Indonesian label for each rule, because "{rule}" in the catalogue
     * sentence has to be something a user reads rather than a validator name.
     */
    protected function ruleName(string $rule): string
    {
        return match ($rule) {
            'email' => 'bentuk alamat email',
            'unique' => 'sudah terdaftar',
            'exists' => 'tidak ditemukan',
            'in', 'not_in' => 'bukan pilihan yang tersedia',
            'min', 'size' => 'panjang minimum',
            'max' => 'panjang maksimum',
            'numeric', 'integer' => 'angka',
            'boolean' => 'ya atau tidak',
            'array' => 'daftar',
            'date' => 'tanggal',
            'after', 'after_or_equal' => 'setelah tanggal yang ditentukan',
            'before', 'before_or_equal' => 'sebelum tanggal yang ditentukan',
            'accepted' => 'harus disetujui',
            'confirmed', 'same' => 'harus sama',
            'string' => 'teks',
            'digits' => 'jumlah digit',
            'regex' => 'bentuk yang diminta',
            'mimes' => 'tipe berkas',
            'url' => 'alamat web',
            'gt' => 'harus lebih besar',
            'gte' => 'harus sama atau lebih besar',
            'lt' => 'harus lebih kecil',
            'lte' => 'harus sama atau lebih kecil',
            'alpha' => 'huruf saja',
            'present', 'required_if', 'prohibited' => 'kehadiran field',
            default => $rule,
        };
    }

    /**
     * Field => label, in Indonesian, for every field the request validates.
     *
     * @return array<string, string>
     */
    abstract protected function fieldLabels(): array;
}
