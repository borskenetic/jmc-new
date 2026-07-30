<?php

namespace App\Services;

use App\Models\GateTerminalClaim;
use App\Models\Setting;
use Illuminate\Support\Carbon;

class GateTerminalService
{
    public const STALE_MINUTES = 5;

    /** @return list<string> */
    public function allGates(): array
    {
        return Setting::gateTerminals();
    }

    /**
     * Gates this terminal may select (unclaimed or already held by this token).
     *
     * @return list<string>
     */
    public function availableGatesFor(string $terminalToken): array
    {
        $terminalToken = trim($terminalToken);
        $gates = $this->allGates();
        if ($gates === []) {
            return [];
        }

        $this->pruneStaleClaims();

        $taken = GateTerminalClaim::query()
            ->whereIn('gate', $gates)
            ->where('terminal_token', '!=', $terminalToken)
            ->pluck('gate')
            ->all();

        return array_values(array_filter(
            $gates,
            fn (string $gate) => ! in_array($gate, $taken, true),
        ));
    }

    public function currentGateFor(string $terminalToken): ?string
    {
        $terminalToken = trim($terminalToken);
        if ($terminalToken === '') {
            return null;
        }

        $this->pruneStaleClaims();

        $claim = GateTerminalClaim::query()
            ->where('terminal_token', $terminalToken)
            ->first();

        return $claim?->gate;
    }

    /**
     * @return array{ok: bool, message?: string, gate?: string}
     */
    public function claim(string $terminalToken, string $gate, bool $force = false): array
    {
        $terminalToken = trim($terminalToken);
        $gate = trim($gate);

        if ($terminalToken === '' || $gate === '') {
            return ['ok' => false, 'message' => 'Terminal and gate are required.'];
        }

        $allowed = $this->allGates();
        if (! in_array($gate, $allowed, true)) {
            return ['ok' => false, 'message' => 'Invalid gate selected.'];
        }

        $this->pruneStaleClaims();

        $existingForGate = GateTerminalClaim::query()->where('gate', $gate)->first();
        if ($existingForGate !== null && $existingForGate->terminal_token !== $terminalToken) {
            if (! $force) {
                return ['ok' => false, 'message' => 'That gate is already in use on another terminal.'];
            }
            // Offline kiosks may take over freely.
            $existingForGate->delete();
        }

        GateTerminalClaim::query()->where('terminal_token', $terminalToken)->delete();

        GateTerminalClaim::updateOrCreate(
            ['terminal_token' => $terminalToken],
            ['gate' => $gate, 'last_seen_at' => now()],
        );

        return ['ok' => true, 'gate' => $gate];
    }

    public function ping(string $terminalToken): void
    {
        $terminalToken = trim($terminalToken);
        if ($terminalToken === '') {
            return;
        }

        GateTerminalClaim::query()
            ->where('terminal_token', $terminalToken)
            ->update(['last_seen_at' => now()]);
    }

    public function release(string $terminalToken): void
    {
        $terminalToken = trim($terminalToken);
        if ($terminalToken === '') {
            return;
        }

        GateTerminalClaim::query()->where('terminal_token', $terminalToken)->delete();
    }

    public function releaseGatesNotInList(array $gates): void
    {
        $gates = array_values(array_filter(array_map('trim', $gates)));
        if ($gates === []) {
            GateTerminalClaim::query()->delete();

            return;
        }

        GateTerminalClaim::query()->whereNotIn('gate', $gates)->delete();
    }

    public function pruneStaleClaims(): void
    {
        $cutoff = Carbon::now()->subMinutes(self::STALE_MINUTES);

        GateTerminalClaim::query()
            ->where('last_seen_at', '<', $cutoff)
            ->delete();
    }

    public function validateGateForScan(?string $gate): ?string
    {
        $gate = trim((string) $gate);
        if ($gate === '') {
            return null;
        }

        $allowed = $this->allGates();
        if ($allowed === [] || ! in_array($gate, $allowed, true)) {
            return null;
        }

        return $gate;
    }
}
