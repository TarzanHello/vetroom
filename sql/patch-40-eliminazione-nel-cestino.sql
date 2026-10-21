-- =============================================================
--  VETROOM · PATCH 40 · Eliminazione della struttura nel cestino
--  21 ottobre 2026
--
--  Come si esegue:
--  Supabase → SQL Editor → New query → incolla tutto → Run.
--  Si può eseguire più volte senza problemi.
--
--  Cosa cambia:
--  • Quando il titolare elimina la sua struttura dal gestionale
--    (Impostazioni → "Elimina la clinica"), la struttura NON viene
--    più cancellata subito: va nel Cestino della Plancia per 30 giorni,
--    chiusa (nessuno la vede più, né lo staff né i proprietari),
--    ma recuperabile con "Ripristina". Dopo 30 giorni la elimini
--    tu dal Cestino, come per le strutture che metti tu nel cestino.
--  • Una pagina vecchia rimasta aperta non può più cancellare una
--    struttura direttamente: chiede di aggiornare la pagina.
--  • Diario di bordo: compare "ha eliminato la clinica …" (nel cestino
--    o definitivamente) al posto delle righe che confondevano.
--    "Ha riattivato la clinica" senza nome era un errore del diario:
--    la cancellazione di una struttura veniva letta come riattivazione.
--    Spariscono anche i "ha lasciato il team" causati dalla cancellazione.
--
--  Non tocca le funzioni che esistono già: aggiunge solo una funzione
--  e tre controlli automatici (trigger).
-- =============================================================

-- Motivo scritto sulla struttura chiusa dal titolare. Le pagine lo riconoscono:
-- non deve cambiare (vedi VR.CLOSED_BY_OWNER in app/js/app.js).
--   'Eliminata dal titolare'

-- 1. Il titolare manda la sua struttura nel cestino ---------------------
create or replace function public.trash_my_clinic(p_clinic uuid, p_confirm_name text)
returns text language plpgsql volatile security definer set search_path = public, pg_temp as $$
declare uid uuid := auth.uid(); c record; w text;
begin
  if uid is null then raise exception 'Accesso richiesto.'; end if;
  select * into c from public.clinics where id = p_clinic;
  if not found then raise exception 'Struttura non trovata.'; end if;
  if not exists (select 1 from public.clinic_members m
                  where m.clinic_id = p_clinic and m.user_id = uid and m.role = 'admin' and m.status = 'active') then
    raise exception 'Solo l''amministratore può eliminare la struttura.';
  end if;
  if c.code_owner_id is not null and c.code_owner_id <> uid then
    raise exception 'Solo il titolare può eliminare la struttura.';
  end if;
  if lower(trim(coalesce(p_confirm_name, ''))) <> lower(trim(coalesce(c.name, ''))) then
    raise exception 'Il nome non corrisponde: la struttura non è stata eliminata.';
  end if;
  if exists (select 1 from public.platform_trash t where t.kind = 'clinic' and t.target_id = p_clinic and t.status = 'waiting') then
    raise exception 'Questa struttura è già in eliminazione.';
  end if;

  w := coalesce(public.vr_confirm_word('clinic', p_clinic), c.name);
  insert into public.platform_trash (kind, target_id, label, code, reason, prev, deleted_by)
  values ('clinic', p_clinic, c.name, w, 'Eliminata dal titolare dal gestionale',
          jsonb_build_object('status', c.status, 'suspended_reason', c.suspended_reason, 'suspended_at', c.suspended_at), uid);

  update public.clinics set status = 'suspended', suspended_reason = 'Eliminata dal titolare', suspended_at = now()
   where id = p_clinic;

  -- al posto di "ha sospeso la clinica", una riga sola e chiara
  begin
    delete from public.platform_audit a
     where a.clinic_id = p_clinic and a.at = now() and a.action = 'clinica.sospesa';
  exception when others then null;
  end;
  perform public.vr_audit('clinica.cestino', 'clinic', p_clinic, p_clinic, c.name);

  return 'Struttura eliminata. Resta recuperabile per 30 giorni: se hai cambiato idea scrivi all''assistenza Vetroom.';
end $$;
revoke all on function public.trash_my_clinic(uuid, text) from public, anon;
grant execute on function public.trash_my_clinic(uuid, text) to authenticated;


-- 2. Niente più cancellazioni dirette dal gestionale ---------------------
--    Lo staff della struttura non può cancellarla saltando il cestino.
--    Restano permesse: l'amministratore della piattaforma e l'eliminazione
--    definitiva dal Cestino della Plancia.
create or replace function public.vr_clinic_delete_guard_trg()
returns trigger language plpgsql security definer set search_path = public, pg_temp as $$
declare uid uuid := auth.uid();
begin
  if uid is null
     or exists (select 1 from public.platform_admins a where a.user_id = uid)
     or exists (select 1 from public.platform_trash t where t.kind = 'clinic' and t.target_id = old.id and t.status = 'waiting')
     or not exists (select 1 from public.clinic_members m where m.clinic_id = old.id and m.user_id = uid) then
    return old;
  end if;
  raise exception 'Vetroom è stato aggiornato: ricarica la pagina (Ctrl+F5) e ripeti l''eliminazione. Nessun dato è stato cancellato.';
end $$;
drop trigger if exists vr_clinic_delete_guard on public.clinics;
create trigger vr_clinic_delete_guard before delete on public.clinics
  for each row execute function public.vr_clinic_delete_guard_trg();


-- 3. Diario: "ha eliminato la clinica …" quando una struttura sparisce ---
create or replace function public.vr_audit_clinic_gone_trg()
returns trigger language plpgsql security definer set search_path = public, pg_temp as $$
begin
  begin
    -- righe di contorno scritte nella stessa operazione: confondevano
    delete from public.platform_audit a
     where a.clinic_id = old.id and a.at = now()
       and a.action in ('clinica.riattivata', 'clinica.sospesa', 'team.uscito', 'team.modificato');
  exception when others then null;
  end;
  begin
    -- l'eliminazione definitiva dal Cestino la registra già la Plancia
    if not exists (select 1 from public.platform_trash t where t.kind = 'clinic' and t.target_id = old.id and t.status = 'waiting')
       and not exists (select 1 from public.platform_admins a where a.user_id = auth.uid()) then
      perform public.vr_audit('clinica.eliminata', 'clinic', old.id, old.id, old.name);
    end if;
  exception when others then null;
  end;
  return null;
end $$;
drop trigger if exists vr_audit_clinic_gone on public.clinics;
create trigger vr_audit_clinic_gone after delete on public.clinics
  for each row execute function public.vr_audit_clinic_gone_trg();

-- Righe sbagliate che non vanno nel diario:
-- • quando una struttura viene cancellata, il controllo del diario la scambiava per
--   una "riattivazione" (riga "ha riattivato la clinica" senza nome): è l'origine
--   della riga delle 00:09 del 21 ottobre;
-- • le uscite dal team causate dalla cancellazione della struttura.
create or replace function public.vr_audit_filter_trg()
returns trigger language plpgsql security definer set search_path = public, pg_temp as $$
begin
  if new.action in ('clinica.riattivata', 'clinica.sospesa', 'clinica.rinominata') and new.target_id is null then
    return null;
  end if;
  if new.action in ('team.uscito', 'team.modificato') and new.clinic_id is not null
     and not exists (select 1 from public.clinics c where c.id = new.clinic_id) then
    return null;
  end if;
  return new;
end $$;
drop trigger if exists vr_audit_filter on public.platform_audit;
create trigger vr_audit_filter before insert on public.platform_audit
  for each row execute function public.vr_audit_filter_trg();

revoke all on function public.vr_clinic_delete_guard_trg() from public, anon, authenticated;
revoke all on function public.vr_audit_clinic_gone_trg() from public, anon, authenticated;
revoke all on function public.vr_audit_filter_trg() from public, anon, authenticated;

notify pgrst, 'reload schema';

select 'Eliminazione nel cestino' as controllo,
  case when to_regprocedure('public.trash_my_clinic(uuid, text)') is not null then 'OK' else 'Manca' end as esito
union all
select 'Blocco delle cancellazioni dirette',
  case when exists (select 1 from pg_trigger where tgname = 'vr_clinic_delete_guard' and not tgisinternal) then 'OK' else 'Manca' end
union all
select 'Diario delle eliminazioni',
  case when exists (select 1 from pg_trigger where tgname = 'vr_audit_clinic_gone' and not tgisinternal)
        and exists (select 1 from pg_trigger where tgname = 'vr_audit_filter' and not tgisinternal) then 'OK' else 'Manca' end;
