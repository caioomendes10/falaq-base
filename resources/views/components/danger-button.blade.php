<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn btn-danger fw-bold']) }}>
    {{ $slot }}
</button>
