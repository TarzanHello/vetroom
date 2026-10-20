-- =============================================================
--  VETROOM · PATCH 39 · Chiusure di singole ore o giorni
--  20 ottobre 2026
--
--  Come si esegue:
--  Supabase → SQL Editor → New query → incolla tutto → Run.
--  Si può eseguire più volte senza problemi.
--
--  Cosa aggiunge:
--  • la tabella delle chiusure (closures): la struttura è chiusa
--    in un giorno preciso, dalle … alle … (o per giorni interi),
--    con una nota facoltativa
--  • la funzione get_closed_slots, che dice ai proprietari su
--    FurrFinder quali ore sono chiuse (solo gli orari, mai la nota),
--    così non le vedono tra quelle prenotabili
--
--  Chi vede e modifica le chiusure: tutte le persone attive della
--  struttura (amministratori, veterinari e segreteria).
-- =============================================================

create table if not exists public.closures (
  id         uuid primary key default gen_random_uuid(),
  clinic_id  uuid not null references public.clinics(id) on delete cascade,
  starts_at  timestamptz not null,
  ends_at    timestamptz not null,
  note       text,
  created_by uuid default auth.uid() references auth.users(id) on delete set null,
  created_at timestamptz not null default now(),
  constraint closures_range check (ends_at > starts_at and ends_at - starts_at <= interval '62 days'),
  constraint closures_note check (note is null or char_length(note) <= 200)
);

create index if not exists closures_clinic_time on public.closures (clinic_id, starts_at);
create index if not exists closures_created_by on public.closures (created_by);

alter table public.closures enable row level security;

drop policy if exists closures_read   on public.closures;
drop policy if exists closures_insert on public.closures;
drop policy if exists closures_update on public.closures;
drop policy if exists closures_delete on public.closures;

create policy closures_read on public.closures for select to authenticated
  using (public.is_clinic_member(clinic_id));
create policy closures_insert on public.closures for insert to authenticated
  with check (public.is_clinic_member(clinic_id) and (created_by is null or created_by = auth.uid()));
create policy closures_update on public.closures for update to authenticated
  using (public.is_clinic_member(clinic_id))
  with check (public.is_clinic_member(clinic_id));
create policy closures_delete on public.closures for delete to authenticated
  using (public.is_clinic_member(clinic_id));

revoke all on public.closures from anon;
grant select, insert, update, delete on public.closures to authenticated;

-- Le ore chiuse di una struttura, per la prenotazione dei proprietari.
-- Restituisce solo inizio e fine (niente note, niente autore), al massimo 62 giorni alla volta.
create or replace function public.get_closed_slots(p_clinic uuid, p_from timestamptz, p_to timestamptz)
returns table (starts_at timestamptz, ends_at timestamptz)
language sql stable security definer set search_path = public as $$
  select c.starts_at, c.ends_at
  from public.closures c
  where auth.uid() is not null
    and c.clinic_id = p_clinic
    and p_to > p_from and p_to - p_from <= interval '62 days'
    and c.starts_at < p_to and c.ends_at > p_from
  order by c.starts_at
$$;

revoke all on function public.get_closed_slots(uuid, timestamptz, timestamptz) from public, anon;
grant execute on function public.get_closed_slots(uuid, timestamptz, timestamptz) to authenticated;

notify pgrst, 'reload schema';

select 'Chiusure' as controllo,
  case when to_regclass('public.closures') is not null then 'OK' else 'Tabella mancante' end as esito
union all
select 'Ore chiuse per FurrFinder',
  case when to_regprocedure('public.get_closed_slots(uuid, timestamptz, timestamptz)') is not null then 'OK' else 'Manca' end;
