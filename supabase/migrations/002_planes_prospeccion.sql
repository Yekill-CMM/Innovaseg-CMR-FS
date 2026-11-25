-- =====================================================================
-- CRM COMERCIAL · Migración 002 · Planes de prospección por vertical
-- Requiere: 001_fase1_crm_core.sql
-- Contenido: guiones por vertical (playbooks), planes mensuales con metas,
-- empresas objetivo, cadencia de contacto, activación que genera prospectos
-- y tareas, avance automático y RUT opcional durante la prospección.
-- =====================================================================
set search_path = crm, public;

-- ---------------------------------------------------------------------
-- 1. RUT opcional en prospección (obligatorio para ofertar)
-- ---------------------------------------------------------------------
alter table crm.companies alter column rut drop not null;
alter table crm.companies drop constraint companies_rut_valid;
alter table crm.companies add constraint companies_rut_valid check (rut is null or crm.rut_is_valid(rut));
drop index crm.companies_rut_uq;
create unique index companies_rut_uq on crm.companies(rut_norm) where deleted_at is null and rut is not null;
create unique index companies_legal_name_uq on crm.companies(lower(legal_name)) where deleted_at is null;
alter table crm.companies add column subsegment text;

create or replace function crm.offer_requires_rut() returns trigger
language plpgsql as $$
begin
  if exists (select 1 from crm.opportunities o join crm.companies c on c.id = o.company_id
             where o.id = new.opportunity_id and c.rut is null) then
    raise exception 'La empresa no tiene RUT registrado: complételo antes de crear la oferta';
  end if;
  return new;
end $$;
create trigger offers_require_rut before insert on crm.offers
  for each row execute function crm.offer_requires_rut();

-- ---------------------------------------------------------------------
-- 2. Tipos
-- ---------------------------------------------------------------------
create type crm.plan_status        as enum ('Borrador','Activo','Cerrado');
create type crm.plan_target_status as enum ('Pendiente','En cadencia','Contactado','Reunión','Oportunidad','Descartado');

-- ---------------------------------------------------------------------
-- 3. Guiones por vertical (plantillas reutilizables)
-- ---------------------------------------------------------------------
create table crm.playbooks (
  id            uuid primary key default gen_random_uuid(),
  industry_id   uuid not null references crm.industries(id),
  name          text not null unique,
  service       text,
  value_prop    text,
  subsegments   jsonb not null default '[]',
  target_roles  jsonb not null default '[]',
  pains         jsonb not null default '[]',
  questions     jsonb not null default '[]',
  objections    jsonb not null default '[]',   -- [{"objecion":"...","respuesta":"..."}]
  default_goals jsonb not null default '{}',
  is_active     boolean not null default true,
  created_at    timestamptz not null default now()
);

create table crm.playbook_cadence_steps (
  id             uuid primary key default gen_random_uuid(),
  playbook_id    uuid not null references crm.playbooks(id) on delete cascade,
  day_offset     int not null check (day_offset >= 0),
  activity_type  text not null,
  title          text not null,
  sort_order     int not null default 0
);
create index pcs_playbook_idx on crm.playbook_cadence_steps(playbook_id, day_offset, sort_order);

-- ---------------------------------------------------------------------
-- 4. Planes mensuales (copian el guion para poder ajustarlo por plan)
-- ---------------------------------------------------------------------
create table crm.prospecting_plans (
  id                  uuid primary key default gen_random_uuid(),
  name                text not null,
  industry_id         uuid not null references crm.industries(id),
  playbook_id         uuid references crm.playbooks(id) on delete set null,
  period_start        date not null,
  period_end          date not null,
  status              crm.plan_status not null default 'Borrador',
  owner_id            uuid not null references crm.users(id),
  objective           text,
  service             text,
  weekly_pace         int not null default 5 check (weekly_pace between 1 and 100),
  value_prop          text,
  target_roles        jsonb not null default '[]',
  pains               jsonb not null default '[]',
  questions           jsonb not null default '[]',
  objections          jsonb not null default '[]',
  goal_companies      int not null default 0 check (goal_companies >= 0),
  goal_contacts       int not null default 0 check (goal_contacts >= 0),
  goal_calls          int not null default 0 check (goal_calls >= 0),
  goal_meetings       int not null default 0 check (goal_meetings >= 0),
  goal_visits         int not null default 0 check (goal_visits >= 0),
  goal_opportunities  int not null default 0 check (goal_opportunities >= 0),
  goal_pipeline_clp   numeric(18,0) not null default 0 check (goal_pipeline_clp >= 0),
  activated_at        timestamptz,
  closed_at           timestamptz,
  created_at          timestamptz not null default now(),
  updated_at          timestamptz not null default now(),
  constraint plan_period_ok check (period_end >= period_start),
  unique (owner_id, industry_id, period_start)
);
create index plans_owner_status_idx on crm.prospecting_plans(owner_id, status);

create table crm.plan_cadence_steps (
  id             uuid primary key default gen_random_uuid(),
  plan_id        uuid not null references crm.prospecting_plans(id) on delete cascade,
  day_offset     int not null check (day_offset >= 0),
  activity_type  text not null,
  title          text not null,
  sort_order     int not null default 0
);
create index plan_cs_idx on crm.plan_cadence_steps(plan_id, day_offset, sort_order);

create table crm.plan_targets (
  id                uuid primary key default gen_random_uuid(),
  plan_id           uuid not null references crm.prospecting_plans(id) on delete cascade,
  company_id        uuid not null references crm.companies(id),
  lead_id           uuid references crm.leads(id) on delete set null,
  status            crm.plan_target_status not null default 'Pendiente',
  priority          int,
  discarded_reason  text,
  added_at          timestamptz not null default now(),
  activated_at      timestamptz,
  unique (plan_id, company_id)
);
create index plan_targets_company_idx on crm.plan_targets(company_id);

alter table crm.tasks add column plan_id uuid references crm.prospecting_plans(id) on delete set null;
alter table crm.tasks add column kind text;
alter table crm.leads add column plan_id uuid references crm.prospecting_plans(id) on delete set null;
create index tasks_plan_idx on crm.tasks(plan_id) where plan_id is not null;

-- Crear un plan desde el guion de su vertical
create or replace function crm.create_plan_from_playbook(p_playbook uuid, p_month date, p_owner uuid default auth.uid())
returns uuid language plpgsql as $$
declare pb record; v_id uuid; v_start date := date_trunc('month', p_month)::date;
begin
  select * into pb from crm.playbooks where id = p_playbook and is_active;
  if pb is null then raise exception 'Guion no encontrado'; end if;
  insert into crm.prospecting_plans(name, industry_id, playbook_id, period_start, period_end, owner_id, service, value_prop,
    target_roles, pains, questions, objections, goal_companies, goal_contacts, goal_calls, goal_meetings, goal_visits,
    goal_opportunities, goal_pipeline_clp)
  values ('Plan '||(select name from crm.industries where id = pb.industry_id)||' '||to_char(v_start,'MM-YYYY'),
    pb.industry_id, pb.id, v_start, (v_start + interval '1 month - 1 day')::date, p_owner, pb.service, pb.value_prop,
    pb.target_roles, pb.pains, pb.questions, pb.objections,
    coalesce((pb.default_goals->>'empresas')::int,0), coalesce((pb.default_goals->>'contactos')::int,0),
    coalesce((pb.default_goals->>'llamadas')::int,0), coalesce((pb.default_goals->>'reuniones')::int,0),
    coalesce((pb.default_goals->>'visitas')::int,0), coalesce((pb.default_goals->>'oportunidades')::int,0),
    coalesce((pb.default_goals->>'pipeline')::numeric,0))
  returning id into v_id;
  insert into crm.plan_cadence_steps(plan_id, day_offset, activity_type, title, sort_order)
  select v_id, day_offset, activity_type, title, sort_order from crm.playbook_cadence_steps where playbook_id = pb.id;
  return v_id;
end $$;

-- Activar: crea prospectos y programa la cadencia escalonada en días hábiles.
-- Se puede volver a llamar para incorporar empresas agregadas después.
create or replace function crm.activate_plan(p_plan uuid) returns int
language plpgsql as $$
declare p record; t record; s record; v_lead uuid; v_base date; v_due date; v_stage uuid; i int := 0; n int := 0;
begin
  select * into p from crm.prospecting_plans where id = p_plan for update;
  if p is null then raise exception 'Plan no encontrado'; end if;
  if p.status = 'Cerrado' then raise exception 'El plan está cerrado'; end if;
  if not exists (select 1 from crm.plan_cadence_steps where plan_id = p_plan) then raise exception 'El plan no tiene cadencia'; end if;
  v_base := greatest(p.period_start, current_date);
  select id into v_stage from crm.pipeline_stages where pipeline = 'lead' and code = 'identificado';

  for t in select * from crm.plan_targets where plan_id = p_plan and status = 'Pendiente'
           order by priority nulls last, added_at loop
    select l.id into v_lead from crm.leads l join crm.pipeline_stages st on st.id = l.stage_id
     where l.company_id = t.company_id and not st.is_closed and l.deleted_at is null limit 1;
    if v_lead is null then
      insert into crm.leads(company_id, stage_id, owner_id, source, potential_service, plan_id)
      values (t.company_id, v_stage, p.owner_id, 'Plan: '||p.name, p.service, p.id) returning id into v_lead;
    end if;
    for s in select * from crm.plan_cadence_steps where plan_id = p_plan order by day_offset, sort_order loop
      v_due := v_base + (i / p.weekly_pace) * 7 + s.day_offset;
      v_due := v_due + case extract(isodow from v_due)::int when 6 then 2 when 7 then 1 else 0 end;
      insert into crm.tasks(title, kind, entity_type, entity_id, assignee_id, priority, due_date, source, plan_id)
      values (s.title, s.activity_type, 'lead', v_lead, p.owner_id,
              (case when s.day_offset <= 1 then 'Alta' else 'Media' end)::crm.task_priority, v_due, 'plan', p.id);
      n := n + 1;
    end loop;
    update crm.plan_targets set status = 'En cadencia', activated_at = now(), lead_id = v_lead where id = t.id;
    i := i + 1;
  end loop;

  update crm.prospecting_plans set status = 'Activo', activated_at = coalesce(activated_at, now()) where id = p_plan;
  return n;
end $$;

-- Cerrar: cancela tareas pendientes del plan; prospectos y oportunidades se mantienen
create or replace function crm.close_plan(p_plan uuid) returns int
language plpgsql as $$
declare n int;
begin
  update crm.tasks set status = 'Cancelada' where plan_id = p_plan and status in ('Pendiente','En curso');
  get diagnostics n = row_count;
  update crm.prospecting_plans set status = 'Cerrado', closed_at = now() where id = p_plan;
  return n;
end $$;

-- Descartar una empresa objetivo cancela sus tareas pendientes del plan
create or replace function crm.on_target_discarded() returns trigger
language plpgsql as $$
begin
  if new.status = 'Descartado' and old.status <> 'Descartado' and new.lead_id is not null then
    update crm.tasks set status = 'Cancelada'
     where plan_id = new.plan_id and entity_type = 'lead' and entity_id = new.lead_id and status in ('Pendiente','En curso');
  end if;
  return null;
end $$;
create trigger plan_targets_discard after update of status on crm.plan_targets
  for each row execute function crm.on_target_discarded();

-- Avance automático del estado de la empresa objetivo según la actividad registrada
create or replace function crm.advance_plan_targets() returns trigger
language plpgsql security definer set search_path = crm as $$
declare v_new crm.plan_target_status;
begin
  if new.occurred_on > current_date or new.company_id is null then return null; end if;
  v_new := case
    when new.activity_type in ('Reunión','Videollamada','Presentación','Visita a terreno') then 'Reunión'
    when new.activity_type in ('Llamada','Email','WhatsApp') then 'Contactado' end;
  if v_new is null then return null; end if;
  update crm.plan_targets t set status = v_new
    from crm.prospecting_plans p
   where p.id = t.plan_id and p.status = 'Activo' and t.company_id = new.company_id and t.status < v_new;
  return null;
end $$;
create trigger activities_advance_plan after insert on crm.activities
  for each row execute function crm.advance_plan_targets();

create or replace function crm.plan_target_to_opportunity() returns trigger
language plpgsql security definer set search_path = crm as $$
begin
  update crm.plan_targets t set status = 'Oportunidad'
    from crm.prospecting_plans p
   where p.id = t.plan_id and p.status = 'Activo' and t.company_id = new.company_id and t.status < 'Oportunidad';
  return null;
end $$;
create trigger opportunities_plan_target after insert on crm.opportunities
  for each row execute function crm.plan_target_to_opportunity();

-- ---------------------------------------------------------------------
-- 5. Avance del plan vs metas (calculado con actividades reales)
-- ---------------------------------------------------------------------
create or replace view crm.v_plan_progress with (security_invoker = on) as
select p.id as plan_id, p.name, p.status, p.owner_id, p.period_start, p.period_end,
  coalesce(act.companies_worked,0) as companies_worked, p.goal_companies,
  coalesce(con.new_contacts,0)     as new_contacts,     p.goal_contacts,
  coalesce(act.calls,0)            as calls,            p.goal_calls,
  coalesce(act.meetings,0)         as meetings,         p.goal_meetings,
  coalesce(act.visits,0)           as visits,           p.goal_visits,
  coalesce(opp.opportunities,0)    as opportunities,    p.goal_opportunities,
  coalesce(opp.pipeline_clp,0)     as pipeline_clp,     p.goal_pipeline_clp,
  round(100.0 * greatest(least(current_date, p.period_end) - p.period_start + 1, 0) / (p.period_end - p.period_start + 1)) as pct_period_elapsed
from crm.prospecting_plans p
left join lateral (
  select count(distinct a.company_id) as companies_worked,
         count(*) filter (where a.activity_type = 'Llamada') as calls,
         count(*) filter (where a.activity_type in ('Reunión','Videollamada','Presentación')) as meetings,
         count(*) filter (where a.activity_type = 'Visita a terreno') as visits
  from crm.activities a join crm.plan_targets t on t.company_id = a.company_id and t.plan_id = p.id
  where a.occurred_on between p.period_start and least(p.period_end, current_date)) act on true
left join lateral (
  select count(*) as new_contacts from crm.contacts c join crm.plan_targets t on t.company_id = c.company_id and t.plan_id = p.id
  where c.created_at::date between p.period_start and p.period_end and c.deleted_at is null) con on true
left join lateral (
  select count(*) as opportunities, sum(v.tcv_clp) as pipeline_clp
  from crm.v_opportunities v join crm.plan_targets t on t.company_id = v.company_id and t.plan_id = p.id
  where v.created_at::date between p.period_start and p.period_end) opp on true;

-- ---------------------------------------------------------------------
-- 6. Seguridad, auditoría y timestamps
-- ---------------------------------------------------------------------
alter table crm.playbooks enable row level security;
alter table crm.playbook_cadence_steps enable row level security;
alter table crm.prospecting_plans enable row level security;
alter table crm.plan_cadence_steps enable row level security;
alter table crm.plan_targets enable row level security;

create policy playbooks_read  on crm.playbooks for select using (crm.is_active_user());
create policy playbooks_write on crm.playbooks for all using (crm.is_manager()) with check (crm.is_manager());
create policy pb_steps_read   on crm.playbook_cadence_steps for select using (crm.is_active_user());
create policy pb_steps_write  on crm.playbook_cadence_steps for all using (crm.is_manager()) with check (crm.is_manager());

create policy plans_read   on crm.prospecting_plans for select using (crm.can_read_all() or owner_id = auth.uid());
create policy plans_insert on crm.prospecting_plans for insert with check (crm.is_manager() or owner_id = auth.uid());
create policy plans_update on crm.prospecting_plans for update using (crm.is_manager() or owner_id = auth.uid());
create policy plans_delete on crm.prospecting_plans for delete using (crm.is_manager() or (owner_id = auth.uid() and status = 'Borrador'));
create policy plan_steps_all on crm.plan_cadence_steps for all using (exists (select 1 from crm.prospecting_plans p
  where p.id = plan_id and (crm.can_read_all() or p.owner_id = auth.uid())));
create policy plan_targets_all on crm.plan_targets for all using (exists (select 1 from crm.prospecting_plans p
  where p.id = plan_id and (crm.can_read_all() or p.owner_id = auth.uid())));

create trigger prospecting_plans_audit after insert or update or delete on crm.prospecting_plans for each row execute function crm.audit_row();
create trigger plan_targets_audit      after insert or update or delete on crm.plan_targets      for each row execute function crm.audit_row();
create trigger prospecting_plans_touch before update on crm.prospecting_plans for each row execute function crm.touch_updated_at();

-- ---------------------------------------------------------------------
-- 7. Guiones iniciales: Retail y Energía
-- ---------------------------------------------------------------------
insert into crm.playbooks(industry_id, name, service, value_prop, subsegments, target_roles, pains, questions, objections, default_goals)
select id, 'Retail', 'Monitoreo remoto 24/7, CCTV con analítica y guardias',
 'Reducir merma y robos con una operación integrada: CCTV con analítica en puntos críticos, monitoreo remoto 24/7 que optimiza la dotación nocturna de guardias, control de acceso en bodegas y CD, y reportes de incidentes que sirven como evidencia. Partir con un piloto medible en 2 o 3 locales.',
 '["Supermercados","Tiendas por departamento","Mejoramiento del hogar","Farmacias","Centros comerciales","Conveniencia y estaciones de servicio","Vestuario y especialidad","Centros de distribución retail"]',
 '["Gerente de Prevención de Pérdidas","Subgerente de Seguridad Corporativa","Gerente de Operaciones de Tiendas","Jefe de Mantención o Facility","Gerente de Logística / CD","Gerente de Administración y Finanzas"]',
 '["Merma por hurto externo e interno sobre la meta de la cadena","Robos violentos y turbazos en tienda","Robos nocturnos a locales y a camiones en patios de CD","Costo creciente de guardias y dificultad para cubrir turnos","CCTV instalado pero sin monitoreo efectivo","Sistemas distintos por local, sin visión centralizada","Adecuación a la nueva Ley de Seguridad Privada (21.659)"]',
 '["¿Cuál es el % de merma y qué parte atribuyen a hurto?","¿Cuántos locales y CD tienen, y cuántos cuentan con CCTV monitoreado?","¿Cuántos eventos de robo o violencia tuvieron en los últimos 12 meses?","¿Quién presta hoy guardias y monitoreo, y cuándo vencen esos contratos?","¿La compra se decide por local o de forma centralizada? ¿Licitan anualmente?","¿Qué indicador tendría que mejorar para considerar exitoso un piloto?"]',
 '[{"objecion":"Ya tenemos proveedor de seguridad","respuesta":"Un piloto acotado en locales críticos permite comparar resultados con datos."},{"objecion":"Es muy caro","respuesta":"Comparar el costo de un guardia nocturno por local versus monitoreo remoto con disuasión por audio."},{"objecion":"Lo vemos en la licitación anual","respuesta":"Pedir la fecha, quedar en el registro de proveedores y aportar insumos técnicos para las bases."},{"objecion":"Seguridad lo ve cada local","respuesta":"Identificar quién consolida los indicadores de merma: ese es el interlocutor corporativo."}]',
 '{"empresas":20,"contactos":25,"llamadas":50,"reuniones":8,"visitas":4,"oportunidades":3,"pipeline":250000000}'
from crm.industries where name = 'Retail' and parent_id is null;

insert into crm.playbooks(industry_id, name, service, value_prop, subsegments, target_roles, pains, questions, objections, default_goals)
select id, 'Energía', 'Protección perimetral, CCTV térmico y monitoreo 24/7',
 'Proteger activos remotos y la continuidad operacional con protección perimetral, CCTV térmico con analítica, monitoreo 24/7 y conectividad para sitios aislados, reduciendo dotación de guardias sin bajar el nivel de protección. Acompañar desde la construcción (EPC) hasta la operación.',
 '["Parques fotovoltaicos","Parques eólicos","Subestaciones y transmisión","Almacenamiento (BESS)","Generación térmica e hidro","EPC en construcción"]',
 '["Gerente de O&M","Gerente de Activos","Jefe de Seguridad Patrimonial","Gerente HSE","Gerente de Proyecto EPC","Abastecimiento / Contratos"]',
 '["Robo de cable, conductores y paneles","Intrusiones en subestaciones no atendidas","Pérdidas por indisponibilidad y penalizaciones","Sitios remotos sin conectividad ni respuesta rápida","Costo alto de guardias 24/7 en zonas aisladas","Exigencias de aseguradoras y del mandante"]',
 '["¿Cuántos sitios y MW operan o construyen, y en qué regiones?","¿Qué incidentes de robo o intrusión tuvieron y cuál fue su costo?","¿El activo está en construcción u operación? ¿Quién decide: dueño, O&M o EPC?","¿Qué proveedor tienen hoy y cuándo vence el contrato?","¿Qué conectividad tienen los sitios?"]',
 '[{"objecion":"En construcción lo resuelve el EPC","respuesta":"Entrar desde la construcción con una solución que se traspasa a operación evita rehacer la inversión."},{"objecion":"No hay presupuesto este año","respuesta":"Cuantificar la pérdida por robo e indisponibilidad frente al costo anual del servicio."},{"objecion":"Los guardias son suficientes","respuesta":"La tecnología los hace efectivos con detección temprana en todo el perímetro."}]',
 '{"empresas":12,"contactos":20,"llamadas":35,"reuniones":6,"visitas":4,"oportunidades":3,"pipeline":500000000}'
from crm.industries where name = 'Energía' and parent_id is null;

insert into crm.playbook_cadence_steps(playbook_id, day_offset, activity_type, title, sort_order)
select pb.id, s.d, s.t, s.title, s.o from crm.playbooks pb
join (values
 ('Retail',0,'Investigación','Investigar la cadena (locales, CD, incidentes) y mapear contactos clave en LinkedIn',1),
 ('Retail',1,'Llamada','Llamada 1: presentación breve y pregunta por el responsable de prevención de pérdidas',2),
 ('Retail',1,'Email','Email de presentación con caso de monitoreo remoto y analítica en retail',3),
 ('Retail',4,'WhatsApp','Contacto por LinkedIn o WhatsApp con el tomador de decisión',4),
 ('Retail',7,'Llamada','Llamada 2: pedir 20 minutos para un diagnóstico de merma y seguridad',5),
 ('Retail',10,'Email','Email 2: propuesta de piloto en 2 o 3 locales críticos con métricas',6),
 ('Retail',14,'Llamada','Llamada 3: cierre del ciclo (agendar reunión o pasar a nurturing)',7),
 ('Retail',21,'Visita a terreno','Visita a local o CD si hay reunión agendada; si no, cerrar el ciclo',8),
 ('Energía',0,'Investigación','Investigar el portafolio (sitios, MW, fase) y mapear contactos de O&M y seguridad',1),
 ('Energía',1,'Llamada','Llamada 1: presentación y pregunta por el responsable de seguridad de activos',2),
 ('Energía',1,'Email','Email con caso de protección perimetral y monitoreo en parque solar',3),
 ('Energía',4,'WhatsApp','Contacto por LinkedIn o WhatsApp con el decisor',4),
 ('Energía',7,'Llamada','Llamada 2: pedir reunión de diagnóstico de riesgos del sitio',5),
 ('Energía',10,'Email','Email 2: propuesta de levantamiento en terreno sin costo',6),
 ('Energía',14,'Llamada','Llamada 3: cierre del ciclo (agendar reunión o pasar a nurturing)',7),
 ('Energía',21,'Visita a terreno','Levantamiento en terreno si hay reunión; si no, cerrar el ciclo',8)
) s(pb, d, t, title, o) on s.pb = pb.name;
